<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Plugins\StoragePlugin;

use Milpa\Attributes\PluginMetadata;
use Milpa\Data\RepositoryFactory;
use Milpa\Data\RepositoryInterface;
use Milpa\ExampleBlog\Blog\Post;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Runtime\Config;
use Milpa\Runtime\Support\RootResolver;

/** Plugin A — PROVIDES the PostStorage capability, backed by whatever milpa/data backend the app's `storage` config names. */
#[PluginMetadata(
    version: '0.1.0',
    author: 'Milpa',
    site: 'https://github.com/getmilpa/example-agent-ready-blog',
    name: 'StoragePlugin',
    type: 'Service',
    provides: [RepositoryInterface::class],
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
        // plugins as `new StoragePlugin($container)`, so the storage choice cannot arrive as a
        // constructor arg. It travels through the app-config bag milpa/runtime registers from
        // Kernel::boot()'s `config` key — the seam that replaces BOTH env-var globals and a
        // widened constructor. See Milpa\Runtime\Config.
        //
        // Since milpa/data 0.2 this plugin names NO backend: RepositoryFactory reads the app's
        // `storage` block — `driver` picks 'file', 'sqlite', 'mysql' or 'memory', the remaining
        // keys are that backend's constructor args — and builds the matching repository behind
        // the same RepositoryInterface capability BlogPlugin requires. Switching the blog from
        // a JSON file to SQLite is one config line in Kernel::boot(); this method never changes.
        // With no `storage` block at all, the default below still persists, to a JSON file.
        $storage = $this->container->get(Config::class)->get('storage', [
            'driver' => 'file',
            'path' => (new RootResolver())->resolve() . '/var/posts.json',
        ]);
        \assert(\is_array($storage));

        $this->container->registerService(
            RepositoryInterface::class,
            RepositoryFactory::fromConfig($storage, Post::class),
        );
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
