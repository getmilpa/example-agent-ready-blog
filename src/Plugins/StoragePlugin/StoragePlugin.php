<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Plugins\StoragePlugin;

use Milpa\Attributes\PluginMetadata;
use Milpa\ExampleBlog\Blog\PostStorageInterface;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Runtime\Config;

/** Plugin A — PROVIDES the PostStorage capability. */
#[PluginMetadata(
    version: '0.1.0',
    author: 'Milpa',
    site: 'https://github.com/getmilpa/example-agent-ready-blog',
    name: 'StoragePlugin',
    type: 'Service',
    provides: [PostStorageInterface::class],
)]
final class StoragePlugin implements PluginInterface
{
    public function __construct(
        private readonly DIContainerInterface $container,
    ) {
    }

    public function boot(): void
    {
        // PluginInterface fixes the constructor to ($container) and milpa/runtime instantiates
        // plugins as `new StoragePlugin($container)`, so the storage path cannot arrive as a
        // constructor arg. It travels through the app-config bag milpa/runtime registers from
        // Kernel::boot()'s `config` key — the seam that replaces BOTH env-var globals and a
        // widened constructor. See Milpa\Runtime\Config.
        /** @var Config $config */
        $config = $this->container->get(Config::class);
        /** @var string $file */
        $file = $config->get('storage.path');
        $this->container->registerService(PostStorageInterface::class, new JsonPostStorage($file));
    }

    public function install(): void
    {
    }

    public function uninstall(): void
    {
    }

    public function enable(): void
    {
    }

    public function disable(): void
    {
    }
}
