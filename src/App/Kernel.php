<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\App;

use Milpa\Container\DIContainer;
use Milpa\Eventing\EventDispatcher;
use Milpa\ExampleBlog\Plugins\AgentToolsPlugin\AgentToolsPlugin;
use Milpa\ExampleBlog\Plugins\BlogPlugin\BlogPlugin;
use Milpa\ExampleBlog\Plugins\StoragePlugin\StoragePlugin;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Runtime\Kernel as RuntimeKernel;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ToolRuntime\Verification\HumanVerifier;
use Milpa\ToolRuntime\Verification\VerificationTool;
use Psr\Log\NullLogger;

/**
 * Thin host bootstrap over {@see RuntimeKernel}. The container, event dispatcher, capability-graph
 * check, dependency-ordered plugin boot and tool-provider auto-registration are ALL delegated to
 * `milpa/runtime` — this class only wires the two collaborators the runtime deliberately does not
 * own (they belong to `milpa/tool-runtime`, the host's opt-in): the {@see HumanVerifier} and the
 * {@see ToolRegistry} it seeds with the `request_verification`/`resolve_verification` tools before
 * handing the registry to the runtime as `$config['toolRegistry']`.
 *
 * This is the dogfood: the ~440 lines of inline Container/EventDispatcher/CapabilityGraph/Router
 * seams the example used to carry are gone — `milpa/runtime` supplies the faithful equivalents.
 */
final class Kernel
{
    private function __construct(
        private readonly RuntimeKernel $runtime,
        private readonly ToolRegistry $registry,
        private readonly HumanVerifier $verifier,
    ) {
    }

    public static function boot(?string $storageFile = null, ?string $eventsFile = null): self
    {
        $root = \dirname(__DIR__, 2);
        // THE BACKEND IS THIS ONE CONFIG LINE. `driver` picks the milpa/data backend —
        // 'file' (one JSON file), 'sqlite' (a real database in one file), 'mysql' or 'memory' —
        // and StoragePlugin hands the block to RepositoryFactory::fromConfig() untouched, so
        // flipping the driver here re-homes every post with ZERO plugin/tool/test code changes.
        // The path stays overridable per-call (tests, bin/mcp-server.php argv) because the
        // config-driven plugin registry cannot pass constructor args — it travels through
        // milpa/runtime's app-config bag (the `config` key below registers a Milpa\Runtime\Config
        // the plugin reads in boot()).
        $storage = [
            'driver' => 'sqlite', // ← was 'file' + var/posts.json until milpa/data 0.2 — that whole migration is this line
            'path' => $storageFile ?? $root . '/var/blog.db',
        ];
        // Same seam, same reason, for the orchestrator's append-only event log (AgentToolsPlugin
        // reads `orchestrator.events_path` when it wires the 3 process tools) — defaults to
        // var/events.jsonl under the host root, per the plan's zero-DB event store.
        $eventsFile ??= $root . '/var/events.jsonl';

        $container = new DIContainer();
        $dispatcher = new EventDispatcher(new NullLogger());

        $verifier = new HumanVerifier($dispatcher);
        $container->registerService(HumanVerifier::class, $verifier);

        $registry = new ToolRegistry(new NullLogger());
        (new VerificationTool($verifier))->register($registry);

        $runtime = RuntimeKernel::boot([
            'root' => $root,
            'container' => $container,
            'dispatcher' => $dispatcher,
            'toolRegistry' => $registry,
            'config' => [
                'storage' => $storage,
                'orchestrator' => ['events_path' => $eventsFile],
            ],
            'plugins' => [
                StoragePlugin::class,
                BlogPlugin::class,
                AgentToolsPlugin::class,
            ],
        ]);

        return new self($runtime, $registry, $verifier);
    }

    public function container(): DIContainerInterface
    {
        return $this->runtime->container();
    }

    public function dispatcher(): MilpaEventDispatcherInterface
    {
        return $this->runtime->dispatcher();
    }

    public function registry(): ToolRegistry
    {
        return $this->registry;
    }

    public function verifier(): HumanVerifier
    {
        return $this->verifier;
    }

    /** @return list<object> */
    public function plugins(): array
    {
        return $this->runtime->plugins();
    }
}
