<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Tests\App;

use Milpa\ExampleBlog\Tests\Support\Sandbox;
use PHPUnit\Framework\TestCase;

/**
 * TWO SESSIONS, ONE HOUSE — over the real wire.
 *
 * Session 1 is an agent: `bin/mcp-server.php` driven as a real subprocess over its actual
 * stdin/stdout pipes (same harness as {@see McpStdioTest}). It proves the 3 process tools ride the
 * SAME registry — they appear in `tools/list` — and that an MCP stdio caller can start a process and
 * read its gate, and can NOT answer it: `stdio` names a pipe, not a person, and the retired
 * `principal` argument is no longer read.
 *
 * Session 2 is a person: `bin/decide.php`, a separate process, signing the decision with a key of
 * its own that the house enrolled. The two sessions share nothing but the house's files — the event
 * log and the database — and the agent's still-open pipe then sees what the person decided.
 */
final class McpProcessToolsTest extends TestCase
{
    private Sandbox $sandbox;

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
        $this->sandbox = Sandbox::create();

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Both paths are injected as bin/mcp-server.php's positional arguments, which
        // Kernel::boot() threads through milpa/runtime's config bag — no env var, no global
        // state, and no cross-test pollution of the shared var/events.jsonl the demo defaults to.
        // The agent's server is told nothing about keys: it has no use for them.
        $projectRoot = \dirname(__DIR__, 2);
        $process = proc_open(
            [\PHP_BINARY, $projectRoot . '/bin/mcp-server.php', $this->sandbox->storage(), $this->sandbox->events()],
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
        $this->sandbox->destroy();
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

    public function testAnAgentOverStdioCanProposeAndCannotAnswerItsOwnGate(): void
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

        // The agent answers its own gate. It is a caller the transport never verified — 'stdio' is
        // whoever holds the pipe — so the gate does not take its answer.
        $asItself = $this->callTool('process_submit_decision', [
            'instance_id' => $instanceId,
            'gate_id' => $gateId,
            'decision' => 'grant',
        ], 5);
        $this->assertFalse($asItself['success']);
        $this->assertSame('UNVERIFIED_APPROVER', $asItself['error']);

        // The old way through: name a human. This is exactly what this test used to do —
        // `'principal' => 'human:mcp-process-test'`, "so resolve() does not throw
        // SelfApprovalException" — and it worked, which was the hole. The argument is gone from the
        // tool's schema; sent anyway, it is not read.
        $asSomebodyElse = $this->callTool('process_submit_decision', [
            'instance_id' => $instanceId,
            'gate_id' => $gateId,
            'decision' => 'grant',
            'principal' => 'human:mcp-process-test',
        ], 6);
        $this->assertFalse($asSomebodyElse['success']);
        $this->assertSame('UNVERIFIED_APPROVER', $asSomebodyElse['error']);

        // Nothing moved: the gate is still open and the post is still a draft.
        $this->assertCount(1, $this->callTool('process_list_pending_approvals', [], 7)['data']['pending']);
        $this->assertSame('draft', $this->statusOf($id, 8));
    }

    public function testThePrincipalArgumentIsGoneFromTheSchemaAnAgentIsShown(): void
    {
        $this->call(['jsonrpc' => '2.0', 'method' => 'initialize', 'params' => [], 'id' => 1]);

        $tools = $this->call(['jsonrpc' => '2.0', 'method' => 'tools/list', 'id' => 2])['result']['tools'];
        $submit = array_values(array_filter($tools, static fn (array $t): bool => $t['name'] === 'process_submit_decision'))[0];

        $arguments = array_keys($submit['inputSchema']['properties']);
        sort($arguments);
        $this->assertSame(['decision', 'gate_id', 'instance_id'], $arguments);
    }

    public function testAPersonDecidesFromTheirOwnSessionAndTheAgentSeesThePostPublished(): void
    {
        $editor = $this->sandbox->editor();
        $this->call(['jsonrpc' => '2.0', 'method' => 'initialize', 'params' => [], 'id' => 1]);

        // Session 1 — the agent, over the pipe.
        $id = $this->callTool('create_post', ['title' => 'Two sessions', 'body' => 'one proposes, one disposes'], 2)['data']['id'];
        $instanceId = $this->callTool('process_instantiate', [
            'definition' => 'publish_post',
            'inputs' => ['post_id' => $id],
        ], 3)['data']['instance_id'];

        // Session 2 — a person, in a process of their own, with a key the agent's process never sees.
        [$exit, $out, $err] = $this->sandbox->run('decide.php', ['--grant', '--instance=' . $instanceId]);
        $this->assertSame(0, $exit, $out . $err);
        $this->assertStringContainsString('signed by ' . $editor->fingerprint(), $out);
        $this->assertStringContainsString('now PUBLISHED', $out);

        // Back in session 1: the agent's still-open pipe sees what the person decided.
        $this->assertCount(0, $this->callTool('process_list_pending_approvals', [], 4)['data']['pending']);
        $this->assertSame('published', $this->statusOf($id, 5));
    }

    public function testAPersonsRejectReopensAFreshGateTheAgentCanSee(): void
    {
        $this->sandbox->editor();
        $this->call(['jsonrpc' => '2.0', 'method' => 'initialize', 'params' => [], 'id' => 1]);

        $id = $this->callTool('create_post', ['title' => 'Reject over two sessions', 'body' => 'body'], 2)['data']['id'];
        $instanceId = $this->callTool('process_instantiate', [
            'definition' => 'publish_post',
            'inputs' => ['post_id' => $id],
        ], 3)['data']['instance_id'];

        [$exit, $out, $err] = $this->sandbox->run('decide.php', ['--reject', '--instance=' . $instanceId]);
        $this->assertSame(0, $exit, $out . $err);
        $this->assertStringContainsString("back at 'review_gate'", $out);

        $pendingAgain = $this->callTool('process_list_pending_approvals', [], 4)['data']['pending'];
        $this->assertCount(1, $pendingAgain);
        $this->assertSame($instanceId, $pendingAgain[0]['instance_id']);
        $this->assertSame('draft', $this->statusOf($id, 5));
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

    public function testAPersonGrantingTheNestedChildGateDrivesTheCampaignToDone(): void
    {
        $this->sandbox->editor();
        $this->call(['jsonrpc' => '2.0', 'method' => 'initialize', 'params' => [], 'id' => 1]);

        $id = $this->callTool('create_post', ['title' => 'Campaign grant, two sessions', 'body' => 'body'], 2)['data']['id'];
        $campaignId = $this->callTool('process_instantiate', [
            'definition' => 'publish_campaign',
            'inputs' => ['post_id' => $id],
        ], 3)['data']['instance_id'];

        $child = $this->callTool('process_list_pending_approvals', [], 4)['data']['pending'][0];
        $this->assertNotSame($campaignId, $child['instance_id']);

        // The agent cannot answer the nested gate either: it is the requester all the way down.
        $byTheAgent = $this->callTool('process_submit_decision', [
            'instance_id' => $child['instance_id'],
            'gate_id' => $child['gate_id'],
            'decision' => 'grant',
        ], 5);
        $this->assertSame('UNVERIFIED_APPROVER', $byTheAgent['error']);

        // A person resolves the LEAF child gate from their own session. That one decision publishes
        // the post AND routes subprocess_done up, so the campaign reaches its own terminal `done`.
        [$exit, $out, $err] = $this->sandbox->run('decide.php', ['--grant', '--instance=' . $child['instance_id']]);
        $this->assertSame(0, $exit, $out . $err);
        $this->assertMatchesRegularExpression('/' . substr($campaignId, 0, 8) . '.*ProcessTerminalReached — done/', $out);

        // Nothing is pending anywhere anymore: the child AND the campaign both reached terminal —
        // the stdio-observable proof the whole nested chain finished.
        $this->assertCount(0, $this->callTool('process_list_pending_approvals', [], 6)['data']['pending']);
        $this->assertSame('published', $this->statusOf($id, 7));
    }

    /** The post's status as the agent's own session reads it, over the pipe. */
    private function statusOf(int $postId, int $id): string
    {
        foreach ($this->callTool('list_posts', [], $id)['data']['posts'] as $post) {
            if ($post['id'] === $postId) {
                return $post['status'];
            }
        }

        self::fail("post #{$postId} is not in list_posts");
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
