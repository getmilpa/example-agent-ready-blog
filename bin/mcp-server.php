#!/usr/bin/env php
<?php

declare(strict_types=1);

use Milpa\ExampleBlog\App\Kernel;
use Milpa\McpServer\JsonRpcService;
use Milpa\ToolRuntime\Contracts\ToolContext;

require __DIR__ . '/../vendor/autoload.php';

// Point your agent at this process over stdio — same tools, same registry, same
// verification choreography as bin/blog.php, just a different transport.
// An optional first argument overrides the storage path (Kernel::boot threads it through
// milpa/runtime's config bag); it defaults to var/posts.json when omitted.
$storageFile = $argv[1] ?? null;
$kernel = Kernel::boot($storageFile);
$service = new JsonRpcService($kernel->registry());

// STDOUT is protocol-only: one JSON-RPC message per line. Human-readable output goes to
// STDERR so it never corrupts the wire.
fwrite(STDERR, 'milpa · example-agent-ready-blog — MCP stdio server ready (close stdin to stop)' . PHP_EOL);

$writeLine = static function (array $response): void {
    fwrite(STDOUT, json_encode($response) . "\n");
    fflush(STDOUT);
};

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }

    $request = json_decode($line, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($request)) {
        $writeLine([
            'jsonrpc' => '2.0',
            'error' => ['code' => -32700, 'message' => 'Parse error'],
            'id' => null,
        ]);
        continue;
    }

    // This transport carries no auth: ToolContext::stdio() (tool-runtime 0.3) is the
    // documented context for exactly this case — process-level trust, principal 'stdio'.
    $ctx = ToolContext::stdio((string) ($request['id'] ?? uniqid('mcp-', true)));

    // Since milpa/mcp-server 0.2 the protocol layer owns the whole JSON-RPC contract:
    // envelope errors and batch refusals come back as well-formed error arrays (never
    // thrown), and notifications — any message without an "id" member — return null.
    // The transport's only job: write what is non-null, write nothing for null.
    $response = $service->handle($request, $ctx);

    if ($response !== null) {
        $writeLine($response);
    }
}
