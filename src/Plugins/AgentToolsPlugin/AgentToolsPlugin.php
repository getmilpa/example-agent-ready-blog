<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Plugins\AgentToolsPlugin;

use Milpa\Attributes\PluginMetadata;
use Milpa\ExampleBlog\Blog\PostStorageInterface;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Interfaces\Tooling\ToolProviderInterface;
use Milpa\Interfaces\Tooling\ToolRegistryInterface;
use Milpa\ToolRuntime\ToolScanner;
use Milpa\ToolRuntime\Verification\HumanVerifier;

/** Plugin C — EXPOSES the blog as agent-callable tools via the ToolProvider seam. */
#[PluginMetadata(
    version: '0.1.0',
    author: 'Milpa',
    site: 'https://github.com/getmilpa/example-agent-ready-blog',
    name: 'AgentToolsPlugin',
    type: 'Tools',
    requires: [PostStorageInterface::class],
)]
final class AgentToolsPlugin implements PluginInterface, ToolProviderInterface
{
    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    public function registerTools(ToolRegistryInterface $registry): void
    {
        /** @var PostStorageInterface $storage */
        $storage = $this->container->get(PostStorageInterface::class);
        /** @var HumanVerifier $verifier */
        $verifier = $this->container->get(HumanVerifier::class);
        (new ToolScanner($registry))->scan(new BlogTools($storage, $verifier));
    }

    /** @return list<string> */
    public function getPromptSections(): array
    {
        return ['Blog tools: create_post, list_posts, publish_post (human-verified).'];
    }

    public function boot(): void
    {
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
