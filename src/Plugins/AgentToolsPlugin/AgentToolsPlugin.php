<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Plugins\AgentToolsPlugin;

use Milpa\Attributes\PluginMetadata;
use Milpa\EventStore\FileEventStore;
use Milpa\ExampleBlog\Blog\PostStorageInterface;
use Milpa\ExampleBlog\Orchestrator\Definitions\PublishPostProcess;
use Milpa\ExampleBlog\Orchestrator\PostDecisionArtifactFactory;
use Milpa\ExampleBlog\Orchestrator\PublishPostTerminalListener;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Interfaces\Tooling\ToolProviderInterface;
use Milpa\Interfaces\Tooling\ToolRegistryInterface;
use Milpa\Orchestrator\HumanGate;
use Milpa\Orchestrator\ProcessDefinitionRegistry;
use Milpa\Orchestrator\ProcessRunner;
use Milpa\Orchestrator\Tools\ProcessInstantiateTool;
use Milpa\Orchestrator\Tools\ProcessListPendingApprovalsTool;
use Milpa\Orchestrator\Tools\ProcessSubmitDecisionTool;
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

        // The orchestrator's process loop, now driven by milpa/orchestrator + milpa/event-store:
        // this plugin only wires the DOMAIN — the publish_post definition, the post decision
        // surface, and the terminal effect — onto the package engine. The generic
        // EventStore/HumanGate/ProcessRunner/tools all come from the packages; they are plain,
        // stateless-except-the-file collaborators (no capability-graph `provides` needed), built
        // here right where they are consumed, mirroring how BlogTools above is built inline.
        /** @var Config $config */
        $config = $this->container->get(Config::class);
        /** @var string $eventsPath */
        $eventsPath = $config->get('orchestrator.events_path');
        $store = new FileEventStore($eventsPath);

        // The name->definition directory the package's process_instantiate tool resolves through;
        // this example registers exactly one process (publish_post).
        $definitions = new ProcessDefinitionRegistry();
        $definitions->register(PublishPostProcess::NAME, PublishPostProcess::build());

        /** @var MilpaEventDispatcherInterface $dispatcher */
        $dispatcher = $this->container->get(MilpaEventDispatcherInterface::class);
        // Finding #4 — the terminal seam. ProcessRunner dispatches `process.terminal` when an
        // instance reaches its terminal state; THIS domain listener (not the generic tool) flips
        // the underlying post to published. Subscribed on the SAME dispatcher the runner fires on.
        $dispatcher->subscribe(
            'process.terminal',
            [new PublishPostTerminalListener($storage), 'onProcessTerminal'],
        );

        // The package HumanGate delegates "what does this gate's decision surface look like?" to
        // the example's DecisionSurfaceFactory; the package ProcessRunner drives auto-advance and
        // fires the terminal seam through the app dispatcher.
        $gate = new HumanGate(new PostDecisionArtifactFactory($storage));
        $runner = new ProcessRunner($dispatcher);

        $scanner->scan(new ProcessInstantiateTool($store, $gate, $runner, $definitions));
        $scanner->scan(new ProcessListPendingApprovalsTool($store, $gate, $definitions));
        $scanner->scan(new ProcessSubmitDecisionTool($store, $gate, $runner, $definitions));
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
