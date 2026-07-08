#!/usr/bin/env php
<?php

declare(strict_types=1);

use Milpa\ExampleBlog\App\Kernel;
use Milpa\McpServer\JsonRpcService;
use Milpa\ToolRuntime\Contracts\ToolContext;

require __DIR__ . '/../vendor/autoload.php';

// Point your agent at this process over stdio — same tools, same registry, same
// verification choreography as bin/blog.php, just a different transport.
$storageFile = getenv('MILPA_BLOG_STORAGE') ?: null;
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

    // A JSON-RPC notification has no "id" member at all — not just a null one — and MUST
    // NOT receive a response (JSON-RPC 2.0 §4.1). array_key_exists (not isset) is required:
    // isset() would misclassify an explicit {"id": null, ...} request as a notification.
    $isNotification = !array_key_exists('id', $request);

    // This transport carries no auth (process-level trust — see README). The `mcp` channel
    // policy in milpa/tool-runtime's PolicyGate requires a non-empty principal regardless
    // (`require_auth: true`): pass one explicitly, or every tools/call comes back FORBIDDEN.
    $ctx = ToolContext::mcp(
        requestId: (string) ($request['id'] ?? uniqid('mcp-', true)),
        principal: 'stdio',
        scopes: ['*'],
    );

    try {
        $response = $service->handle($request, $ctx);
    } catch (\Throwable $e) {
        // JsonRpcService::handle() throws for a malformed envelope (missing/wrong "jsonrpc",
        // missing "method") instead of returning an error array — there's no id to safely
        // key a response on internally, so the transport builds it here.
        $code = $e->getCode();
        $response = [
            'jsonrpc' => '2.0',
            'error' => [
                'code' => is_int($code) && $code !== 0 ? $code : -32603,
                'message' => $e->getMessage(),
            ],
            'id' => $request['id'] ?? null,
        ];
    }

    if ($isNotification) {
        continue;
    }

    $writeLine($response);
}
