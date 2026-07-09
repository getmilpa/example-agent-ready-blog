<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Plugins\AgentToolsPlugin;

use Milpa\Attributes\PluginMetadata;
use Milpa\ExampleBlog\Blog\PostStorageInterface;
use Milpa\ExampleBlog\Orchestrator\EventStore;
use Milpa\ExampleBlog\Orchestrator\HumanGate;
use Milpa\ExampleBlog\Orchestrator\Tools\ProcessInstantiateTool;
use Milpa\ExampleBlog\Orchestrator\Tools\ProcessListPendingApprovalsTool;
use Milpa\ExampleBlog\Orchestrator\Tools\ProcessSubmitDecisionTool;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Interfaces\Tooling\ToolProviderInterface;
use Milpa\Interfaces\Tooling\ToolRegistryInterface;
use Milpa\Runtime\Config;
use Milpa\ToolRuntime\ToolScanner;
use Milpa\ToolRuntime\Verification\HumanVerifier;

/** Plugin C — EXPOSES the blog AND the orchestrator's process loop as agent-callable tools via the ToolProvider seam. */
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
        $scanner = new ToolScanner($registry);
        $scanner->scan(new BlogTools($storage, $verifier));

        // The orchestrator's process loop: EventStore/HumanGate are plain, stateless-except-the-
        // file collaborators (no capability-graph `provides` needed) — built here, right where
        // they're consumed, mirroring how BlogTools above is built inline rather than resolved
        // from the container.
        /** @var Config $config */
        $config = $this->container->get(Config::class);
        /** @var string $eventsPath */
        $eventsPath = $config->get('orchestrator.events_path');
        $store = new EventStore($eventsPath);
        $gate = new HumanGate();

        $scanner->scan(new ProcessInstantiateTool($store, $gate, $storage));
        $scanner->scan(new ProcessListPendingApprovalsTool($store, $gate, $storage));
        $scanner->scan(new ProcessSubmitDecisionTool($store, $gate, $storage));
    }

    /** @return list<string> */
    public function getPromptSections(): array
    {
        return [
            'Blog tools: create_post, list_posts, publish_post (human-verified).',
            'Process tools: process_instantiate, process_list_pending_approvals, process_submit_decision — the publish_post process loop (draft -> review_gate -> published), event-sourced.',
        ];
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
