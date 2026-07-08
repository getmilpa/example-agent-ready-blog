<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Tests\App;

use PHPUnit\Framework\TestCase;

/**
 * Drives `bin/mcp-server.php` as a real subprocess over its actual stdin/stdout pipes —
 * no in-process shortcuts. This is the transport contract, not the registry contract
 * (see KernelLoopTest for the in-process version of the same choreography).
 */
final class McpStdioTest extends TestCase
{
    private string $storageFile;

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
        $this->storageFile = sys_get_temp_dir() . '/mcp-stdio-' . uniqid() . '.json';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // getenv() with no argument (not $_ENV) is what reliably carries the full parent
        // environment regardless of the `variables_order` ini setting — proc_open's $env
        // argument REPLACES the child's environment entirely rather than merging into it.
        $env = getenv();
        $env['MILPA_BLOG_STORAGE'] = $this->storageFile;

        $projectRoot = \dirname(__DIR__, 2);
        $process = proc_open(
            [\PHP_BINARY, $projectRoot . '/bin/mcp-server.php'],
            $descriptors,
            $pipes,
            $projectRoot,
            $env,
        );
        self::assertIsResource($process, 'failed to spawn bin/mcp-server.php');
        $this->process = $process;
        [$this->stdin, $this->stdout, $this->stderr] = $pipes;
        stream_set_blocking($this->stdout, false);
        stream_set_blocking($this->stderr, false);
    }

    protected function tearDown(): void
    {
        // Close stdin first — EOF on STDIN is what makes the server's read loop end and the
        // process exit on its own; closing pipes before proc_close() avoids the deadlock the
        // PHP manual warns about (a still-open pipe can block proc_close() forever).
        fclose($this->stdin);
        fclose($this->stdout);
        fclose($this->stderr);
        proc_close($this->process);
        @unlink($this->storageFile);
    }

    public function testFullChoreographyGrantPath(): void
    {
        $init = $this->call(['jsonrpc' => '2.0', 'method' => 'initialize', 'params' => [], 'id' => 1]);
        $this->assertSame('2025-03-26', $init['result']['protocolVersion']);

        // Notifications (no "id" member) MUST NOT get a response line at all — not even an
        // empty one.
        $this->send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $this->assertNoLine('notifications/initialized must not produce a response line');

        // Raw wire assertion: tools/tool-runtime 0.2.1 fixed getToolSummaries() to emit
        // `"properties":{}` (a JSON object) instead of `"properties":[]` (a JSON array) for
        // zero-argument tools — `[]` is invalid per the MCP/JSON-Schema inputSchema shape.
        // Assert on the raw line, not the decoded array, because PHP's json_decode collapses
        // both `{}` and `[]` into the identical empty-array value and would hide a regression.
        $toolsListLine = $this->rawCall(['jsonrpc' => '2.0', 'method' => 'tools/list', 'id' => 2]);
        $this->assertStringContainsString('"properties":{}', $toolsListLine);
        $this->assertStringNotContainsString('"properties":[]', $toolsListLine);
        $toolsList = json_decode($toolsListLine, true);
        $names = array_map(static fn (array $t): string => $t['name'], $toolsList['result']['tools']);
        sort($names);
        $this->assertSame(['create_post', 'human_verify', 'list_posts', 'publish_post'], $names);

        $id = $this->callTool('create_post', ['title' => 'Hello MCP', 'body' => 'over stdio'], 3)['data']['id'];

        // Call 1 of 3: the registry's mutating-tool confirm gate intercepts publish_post and
        // returns a confirm_token instead of running the tool.
        $gate = $this->callTool('publish_post', ['id' => $id], 4);
        $this->assertTrue($gate['success']);
        $token = $gate['data']['confirm_token'];
        $this->assertNotEmpty($token);

        // Call 2 of 3: redeeming the token runs the tool, which asks the verification seam
        // and returns pending_verification — it does not publish yet.
        $pending = $this->callTool('publish_post', ['id' => $id, 'confirm_token' => $token], 5);
        $this->assertTrue($pending['success']);
        $this->assertSame('pending_verification', $pending['data']['status']);
        $subject = $pending['data']['subject'];
        $requestId = $pending['data']['request_id'];
        $this->assertNotEmpty($requestId);

        // Call 3 of 3: the agent's human grants the verification via human_verify — the
        // actual state change (draft -> published) happens as a reaction to this, inside
        // BlogPlugin's verification.granted handler, not as this call's return value.
        $verify = $this->callTool('human_verify', [
            'subject' => $subject,
            'decision' => 'grant',
            'principal' => 'human:mcp-stdio-test',
            'request_id' => $requestId,
        ], 6);
        $this->assertTrue($verify['success']);
        $this->assertSame('passed', $verify['data']['status']);

        $posts = $this->callTool('list_posts', [], 7)['data']['posts'];
        $published = array_values(array_filter($posts, static fn (array $p): bool => $p['id'] === $id));
        $this->assertCount(1, $published);
        $this->assertSame('published', $published[0]['status']);
    }

    public function testRejectPathLeavesDraft(): void
    {
        $this->call(['jsonrpc' => '2.0', 'method' => 'initialize', 'params' => [], 'id' => 1]);

        $id = $this->callTool('create_post', ['title' => 'Reject me', 'body' => 'body'], 2)['data']['id'];
        $token = $this->callTool('publish_post', ['id' => $id], 3)['data']['confirm_token'];
        $pending = $this->callTool('publish_post', ['id' => $id, 'confirm_token' => $token], 4);
        $subject = $pending['data']['subject'];
        $requestId = $pending['data']['request_id'];

        $verify = $this->callTool('human_verify', [
            'subject' => $subject,
            'decision' => 'reject',
            'principal' => 'human:mcp-stdio-test',
            'request_id' => $requestId,
            'reason' => 'not good enough',
        ], 5);
        $this->assertTrue($verify['success']);
        $this->assertSame('failed', $verify['data']['status']);

        $posts = $this->callTool('list_posts', [], 6)['data']['posts'];
        $draft = array_values(array_filter($posts, static fn (array $p): bool => $p['id'] === $id));
        $this->assertCount(1, $draft);
        $this->assertSame('draft', $draft[0]['status']);
    }

    public function testMalformedJsonLineReturnsParseError(): void
    {
        $this->call(['jsonrpc' => '2.0', 'method' => 'initialize', 'params' => [], 'id' => 1]);

        fwrite($this->stdin, "{not valid json at all\n");
        fflush($this->stdin);
        $line = $this->readLine();
        self::assertNotNull($line, 'expected a parse-error response line');
        $response = json_decode($line, true);

        $this->assertSame('2.0', $response['jsonrpc']);
        $this->assertNull($response['id']);
        $this->assertSame(-32700, $response['error']['code']);
    }

    public function testBatchRequestIsRefusedLoudly(): void
    {
        $this->call(['jsonrpc' => '2.0', 'method' => 'initialize', 'params' => [], 'id' => 1]);

        // A JSON-RPC batch (array of requests) must get an explicit refusal — not silence.
        // A list-array has no "id" key, so without a guard it would be misclassified as a
        // notification and a batching-capable client would hang on ids 100/101 forever.
        $batch = [
            ['jsonrpc' => '2.0', 'method' => 'tools/list', 'id' => 100],
            ['jsonrpc' => '2.0', 'method' => 'tools/list', 'id' => 101],
        ];
        fwrite($this->stdin, json_encode($batch) . "\n");
        fflush($this->stdin);

        $line = $this->readLine();
        self::assertNotNull($line, 'expected an explicit batch-refusal response line');
        $response = json_decode($line, true);

        $this->assertSame('2.0', $response['jsonrpc']);
        $this->assertNull($response['id']);
        $this->assertSame(-32600, $response['error']['code']);

        // The server must still be alive and answering single requests afterwards.
        $after = $this->call(['jsonrpc' => '2.0', 'method' => 'tools/list', 'id' => 2]);
        $this->assertArrayHasKey('tools', $after['result']);
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

    private function assertNoLine(string $message, float $timeoutSeconds = 0.5): void
    {
        $line = $this->readLine($timeoutSeconds);
        self::assertNull($line, $message . ' — got: ' . ($line ?? ''));
    }
}
