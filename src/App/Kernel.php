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

    public static function boot(?string $storageFile = null): self
    {
        $root = \dirname(__DIR__, 2);
        // StoragePlugin is booted by the runtime via `new StoragePlugin($container)` and the
        // config-driven registry cannot pass constructor args — so the storage path travels
        // through milpa/runtime's app-config bag (the `config` key below registers a
        // Milpa\Runtime\Config the plugin reads in boot()). Still overridable per-call for tests;
        // defaults to var/posts.json under the host root.
        $storageFile ??= $root . '/var/posts.json';

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
            'config' => ['storage' => ['path' => $storageFile]],
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
