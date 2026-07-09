<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Plugins\AgentToolsPlugin;

use Milpa\Attributes\PluginMetadata;
use Milpa\Data\RepositoryInterface;
use Milpa\EventStore\FileEventStore;
use Milpa\ExampleBlog\Blog\Post;
use Milpa\ExampleBlog\Orchestrator\Definitions\PublishCampaignProcess;
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
    requires: [RepositoryInterface::class],
)]
final class AgentToolsPlugin implements PluginInterface, ToolProviderInterface
{
    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    public function registerTools(ToolRegistryInterface $registry): void
    {
        /** @var RepositoryInterface<Post> $storage */
        $storage = $this->container->get(RepositoryInterface::class);
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

        // The name->definition directory the package's process_instantiate tool resolves through,
        // AND the seam ProcessRunner resolves a subprocess state's `definitionRef` through at
        // runtime. This example registers BOTH the parent `publish_campaign` and the CHILD
        // `publish_post` it composes as a subprocess — a campaign instantiates a publish_post
        // child, waits for its review_gate to be resolved, then advances itself to `done`.
        $definitions = new ProcessDefinitionRegistry();
        $definitions->register(PublishPostProcess::NAME, PublishPostProcess::build());
        $definitions->register(PublishCampaignProcess::NAME, PublishCampaignProcess::build());

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
        // fires the terminal seam through the app dispatcher. The runner also receives the
        // definition registry so it can resolve `publish_campaign`'s subprocess state onto the
        // `publish_post` child at runtime (rebanada 2 — recursive composition).
        $gate = new HumanGate(new PostDecisionArtifactFactory($storage));
        $runner = new ProcessRunner($dispatcher, $definitions);

        $scanner->scan(new ProcessInstantiateTool($store, $gate, $runner, $definitions));
        $scanner->scan(new ProcessListPendingApprovalsTool($store, $gate, $definitions));
        $scanner->scan(new ProcessSubmitDecisionTool($store, $gate, $runner, $definitions));
    }

    /** @return list<string> */
    public function getPromptSections(): array
    {
        return [
            'Blog tools: create_post, list_posts, publish_post (human-verified).',
            'Process tools: process_instantiate, process_list_pending_approvals, process_submit_decision — the publish_post loop (draft -> review_gate -> published) AND the publish_campaign parent that runs publish_post as a subprocess (draft -> review[subprocess] -> announced -> done), both event-sourced and driven by the same 3 tools.',
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
