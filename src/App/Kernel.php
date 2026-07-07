<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\App;

use Milpa\ExampleBlog\Plugins\AgentToolsPlugin\AgentToolsPlugin;
use Milpa\ExampleBlog\Plugins\BlogPlugin\BlogPlugin;
use Milpa\ExampleBlog\Plugins\StoragePlugin\StoragePlugin;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Interfaces\Tooling\ToolProviderInterface;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ToolRuntime\Verification\HumanVerifier;
use Milpa\ToolRuntime\Verification\HumanVerifyTool;
use Psr\Log\NullLogger;

/**
 * Miniature of a real Milpa host: container + dispatcher + capability graph
 * check + ordered plugin boot + tool registry wiring. 89 lines end to end.
 */
final class Kernel
{
    /** @param list<object> $plugins */
    private function __construct(
        private readonly Container $container,
        private readonly EventDispatcher $dispatcher,
        private readonly ToolRegistry $registry,
        private readonly HumanVerifier $verifier,
        private readonly array $plugins,
    ) {
    }

    public static function boot(?string $storageFile = null): self
    {
        $container = new Container();
        $dispatcher = new EventDispatcher();
        $container->registerService(MilpaEventDispatcherInterface::class, $dispatcher);

        $verifier = new HumanVerifier($dispatcher);
        $container->registerService(HumanVerifier::class, $verifier);

        $plugins = [
            new StoragePlugin($container, $storageFile),
            new BlogPlugin($container),
            new AgentToolsPlugin($container),
        ];

        (new CapabilityGraph())->check($plugins);
        foreach ($plugins as $plugin) {
            $plugin->boot();
        }

        $registry = new ToolRegistry(new NullLogger());
        (new HumanVerifyTool($verifier))->register($registry);
        foreach ($plugins as $plugin) {
            if ($plugin instanceof ToolProviderInterface) {
                $plugin->registerTools($registry);
            }
        }

        return new self($container, $dispatcher, $registry, $verifier, $plugins);
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function dispatcher(): EventDispatcher
    {
        return $this->dispatcher;
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
        return $this->plugins;
    }
}
