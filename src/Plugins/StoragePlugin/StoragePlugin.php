<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Plugins\StoragePlugin;

use Milpa\Attributes\PluginMetadata;
use Milpa\ExampleBlog\Blog\PostStorageInterface;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInterface;

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
        private readonly ?string $storageFile = null,
    ) {
    }

    public function boot(): void
    {
        // milpa/runtime instantiates plugins as `new StoragePlugin($container)` (the config-driven
        // registry cannot pass constructor args), so an explicit path arrives via the
        // MILPA_BLOG_STORAGE env var, set by App\Kernel::boot() / bin/mcp-server.php. The
        // constructor arg still wins when a plugin is built directly (e.g. a unit test).
        $file = $this->storageFile ?? (getenv('MILPA_BLOG_STORAGE') ?: getcwd() . '/var/posts.json');
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
