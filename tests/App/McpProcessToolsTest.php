<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Tests\App;

use PHPUnit\Framework\TestCase;

/**
 * Drives `bin/mcp-server.php` as a real subprocess over its actual stdin/stdout pipes — same
 * harness as {@see McpStdioTest} — to prove the 3 process tools ride the SAME registry: they
 * appear in `tools/list`, and `tools/call process_instantiate` followed by
 * `process_submit_decision` advances a real, event-sourced process instance end-to-end over the
 * wire.
 */
final class McpProcessToolsTest extends TestCase
{
    private string $storageFile;

    private string $eventsFile;

    /** @var resource */
    private $process;

    /** @var resource */
    private $stdin;

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    protected function setUp(): void
    {
        $this->storageFile = sys_get_temp_dir() . '/mcp-process-storage-' . uniqid() . '.db';
        $this->eventsFile = sys_get_temp_dir() . '/mcp-process-events-' . uniqid() . '.jsonl';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Both temp paths are injected as bin/mcp-server.php's positional arguments, which
        // Kernel::boot() threads through milpa/runtime's config bag — no env var, no global
        // state, and no cross-test pollution of the shared var/events.jsonl the demo defaults to.
        $projectRoot = \dirname(__DIR__, 2);
        $process = proc_open(
            [\PHP_BINARY, $projectRoot . '/bin/mcp-server.php', $this->storageFile, $this->eventsFile],
            $descriptors,
            $pipes,
            $projectRoot,
            null,
        );
        self::assertIsResource($process, 'failed to spawn bin/mcp-server.php');
        $this->process = $process;
        [$this->stdin, $this->stdout, $this->stderr] = $pipes;
        stream_set_blocking($this->stdout, false);
        stream_set_blocking($this->stderr, false);
    }

    protected function tearDown(): void
    {
        fclose($this->stdin);
        fclose($this->stdout);
        fclose($this->stderr);
        proc_close($this->process);
        @unlink($this->storageFile);
        @unlink($this->eventsFile);
    }

    public function testTheThreeProcessToolsAppearInToolsList(): void
    {
        $this->call(['jsonrpc' => '2.0', 'method' => 'initialize', 'params' => [], 'id' => 1]);

        $toolsList = $this->call(['jsonrpc' => '2.0', 'method' => 'tools/list', 'id' => 2]);
        $names = array_map(static fn (array $t): string => $t['name'], $toolsList['result']['tools']);
        sort($names);

        $this->assertSame([
            'create_post',
            'list_posts',
            'process_instantiate',
            'process_list_pending_approvals',
            'process_submit_decision',
            'publish_post',
            'request_verification',
            'resolve_verification',
        ], $names);
    }

    public function testInstantiateThenSubmitDecisionAdvancesTheProcessOverStdio(): void
    {
        $this->call(['jsonrpc' => '2.0', 'method' => 'initialize', 'params' => [], 'id' => 1]);

        $id = $this->callTool('create_post', ['title' => 'Over stdio', 'body' => 'the process loop'], 2)['data']['id'];

        $instantiate = $this->callTool('process_instantiate', [
            'definition' => 'publish_post',
            'inputs' => ['post_id' => $id],
        ], 3);
        $this->assertTrue($instantiate['success']);
        $this->assertSame('review_gate', $instantiate['data']['current_state']);
        $instanceId = $instantiate['data']['instance_id'];

        $pending = $this->callTool('process_list_pending_approvals', [], 4);
        $this->assertTrue($pending['success']);
        $this->assertCount(1, $pending['data']['pending']);
        $this->assertSame($instanceId, $pending['data']['pending'][0]['instance_id']);
        $gateId = $pending['data']['pending'][0]['gate_id'];

        $submit = $this->callTool('process_submit_decision', [
            'instance_id' => $instanceId,
            'gate_id' => $gateId,
            'decision' => 'grant',
            // ToolContext::stdio() defaults process_instantiate's requester to principal 'stdio' —
            // this MUST differ, or resolve() throws SelfApprovalException.
            'principal' => 'human:mcp-process-test',
        ], 5);
        $this->assertTrue($submit['success']);
        $this->assertSame('published', $submit['data']['current_state']);
    }

    public function testRejectPathOverStdioReopensAFreshGate(): void
    {
        $this->call(['jsonrpc' => '2.0', 'method' => 'initialize', 'params' => [], 'id' => 1]);

        $id = $this->callTool('create_post', ['title' => 'Reject over stdio', 'body' => 'body'], 2)['data']['id'];
        $instantiate = $this->callTool('process_instantiate', [
            'definition' => 'publish_post',
            'inputs' => ['post_id' => $id],
        ], 3);
        $instanceId = $instantiate['data']['instance_id'];
        $gateId = $this->callTool('process_list_pending_approvals', [], 4)['data']['pending'][0]['gate_id'];

        $submit = $this->callTool('process_submit_decision', [
            'instance_id' => $instanceId,
            'gate_id' => $gateId,
            'decision' => 'reject',
            'principal' => 'human:mcp-process-test',
        ], 5);
        $this->assertTrue($submit['success']);
        $this->assertSame('review_gate', $submit['data']['current_state']);

        $pendingAgain = $this->callTool('process_list_pending_approvals', [], 6);
        $this->assertCount(1, $pendingAgain['data']['pending']);
    }

    public function testInstantiatingACampaignSurfacesTheNestedChildGateOverStdio(): void
    {
        $this->call(['jsonrpc' => '2.0', 'method' => 'initialize', 'params' => [], 'id' => 1]);

        $id = $this->callTool('create_post', ['title' => 'Campaign over stdio', 'body' => 'the subprocess loop'], 2)['data']['id'];

        // Only the PARENT campaign is ever named to process_instantiate — the same 3 tools, over
        // the wire, run publish_post as a subprocess with zero campaign-specific tooling.
        $instantiate = $this->callTool('process_instantiate', [
            'definition' => 'publish_campaign',
            'inputs' => ['post_id' => $id],
        ], 3);
        $this->assertTrue($instantiate['success']);
        $this->assertSame('review', $instantiate['data']['current_state']);
        $campaignId = $instantiate['data']['instance_id'];

        // Nested-gate discovery over stdio: the pending-approvals list surfaces the CHILD
        // publish_post's review_gate, whose instance is NOT the campaign.
        $pending = $this->callTool('process_list_pending_approvals', [], 4);
        $this->assertTrue($pending['success']);
        $this->assertCount(1, $pending['data']['pending']);
        $this->assertNotSame($campaignId, $pending['data']['pending'][0]['instance_id']);
        $options = $pending['data']['pending'][0]['options'];
        sort($options);
        $this->assertSame(['grant', 'reject'], $options);
    }

    public function testGrantingTheNestedChildGateDrivesTheCampaignToDoneOverStdio(): void
    {
        $this->call(['jsonrpc' => '2.0', 'method' => 'initialize', 'params' => [], 'id' => 1]);

        $id = $this->callTool('create_post', ['title' => 'Campaign grant over stdio', 'body' => 'body'], 2)['data']['id'];
        $instantiate = $this->callTool('process_instantiate', [
            'definition' => 'publish_campaign',
            'inputs' => ['post_id' => $id],
        ], 3);
        $campaignId = $instantiate['data']['instance_id'];

        $child = $this->callTool('process_list_pending_approvals', [], 4)['data']['pending'][0];
        $this->assertNotSame($campaignId, $child['instance_id']);

        // Resolving the LEAF child gate publishes the post AND routes subprocess_done up so the
        // campaign reaches its own terminal `done` — all in this one submit over the wire.
        $submit = $this->callTool('process_submit_decision', [
            'instance_id' => $child['instance_id'],
            'gate_id' => $child['gate_id'],
            'decision' => 'grant',
            'principal' => 'human:mcp-campaign-test',
        ], 5);
        $this->assertTrue($submit['success']);
        $this->assertSame('published', $submit['data']['current_state']);

        // Nothing is pending anywhere anymore: the child AND the campaign both reached terminal —
        // the stdio-observable proof the whole nested chain finished.
        $pendingAfter = $this->callTool('process_list_pending_approvals', [], 6);
        $this->assertCount(0, $pendingAfter['data']['pending']);
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array{success: bool, data: mixed, message: ?string, error: ?string, meta: array<string, mixed>}
     */
    private function callTool(string $name, array $args, int $id): array
    {
        $response = $this->call([
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $args],
            'id' => $id,
        ]);

        return json_decode($response['result']['content'][0]['text'], true);
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    private function call(array $request): array
    {
        $decoded = json_decode($this->rawCall($request), true);
        self::assertIsArray($decoded, 'expected a decodable JSON-RPC response line');

        return $decoded;
    }

    /**
     * @param array<string, mixed> $request
     */
    private function rawCall(array $request): string
    {
        $this->send($request);
        $line = $this->readLine();
        self::assertNotNull($line, 'expected a response line for method ' . $request['method']);

        return $line;
    }

    /**
     * @param array<string, mixed> $request
     */
    private function send(array $request): void
    {
        fwrite($this->stdin, json_encode($request) . "\n");
        fflush($this->stdin);
    }

    private function readLine(float $timeoutSeconds = 5.0): ?string
    {
        $buffer = '';
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $remaining = max(0.0, $deadline - microtime(true));
            $sec = (int) floor($remaining);
            $usec = (int) (($remaining - $sec) * 1_000_000);
            $read = [$this->stdout];
            $write = null;
            $except = null;
            $ready = stream_select($read, $write, $except, $sec, $usec);

            if ($ready === false || $ready === 0) {
                continue;
            }

            $chunk = fgets($this->stdout);
            if ($chunk === false) {
                if (feof($this->stdout)) {
                    break;
                }
                continue;
            }

            $buffer .= $chunk;
            if (str_ends_with($buffer, "\n")) {
                return rtrim($buffer, "\n");
            }
        }

        return $buffer !== '' ? $buffer : null;
    }
}
