<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Plugins\StoragePlugin;

use Milpa\Attributes\PluginMetadata;
use Milpa\Data\FileRepository;
use Milpa\Data\RepositoryInterface;
use Milpa\ExampleBlog\Blog\Post;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Runtime\Config;

/** Plugin A — PROVIDES the PostStorage capability, backed by milpa/data's file-JSON repository. */
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
        // plugins as `new StoragePlugin($container)`, so the storage path cannot arrive as a
        // constructor arg. It travels through the app-config bag milpa/runtime registers from
        // Kernel::boot()'s `config` key — the seam that replaces BOTH env-var globals and a
        // widened constructor. See Milpa\Runtime\Config.
        /** @var Config $config */
        $config = $this->container->get(Config::class);
        /** @var string $file */
        $file = $config->get('storage.path');
        // milpa/data's FileRepository is the JsonPostStorage this example used to carry inline: the
        // whole read-modify-write file-JSON collection logic moved upstream, keyed by id, rehydrated
        // via Post::fromArray. This plugin now only wires it — a FileRepository<Post> bound to the
        // config-driven path — behind the same RepositoryInterface capability BlogPlugin requires.
        $this->container->registerService(RepositoryInterface::class, new FileRepository($file, Post::class));
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
