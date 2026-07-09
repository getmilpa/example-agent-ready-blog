<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Tests\Orchestrator;

use Milpa\Eventing\EventDispatcher;
use Milpa\EventStore\FileEventStore;
use Milpa\ExampleBlog\Blog\Post;
use Milpa\ExampleBlog\Orchestrator\Definitions\PublishCampaignProcess;
use Milpa\ExampleBlog\Orchestrator\Definitions\PublishPostProcess;
use Milpa\ExampleBlog\Orchestrator\PostDecisionArtifactFactory;
use Milpa\ExampleBlog\Orchestrator\PublishPostTerminalListener;
use Milpa\ExampleBlog\Tests\Orchestrator\Fixtures\InMemoryPostStorage;
use Milpa\Orchestrator\HumanGate;
use Milpa\Orchestrator\ProcessDefinitionRegistry;
use Milpa\Orchestrator\ProcessInstance;
use Milpa\Orchestrator\ProcessRunner;
use Milpa\Orchestrator\Tools\ProcessInstantiateTool;
use Milpa\Orchestrator\Tools\ProcessListPendingApprovalsTool;
use Milpa\Orchestrator\Tools\ProcessSubmitDecisionTool;
use Milpa\ToolRuntime\Contracts\ToolContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Rebanada 2's consumer proof: the `publish_campaign` PARENT process runs `publish_post` (rebanada
 * 1's process, UNCHANGED) as a SUBPROCESS, driven by the SAME 3 `milpa/orchestrator` tools
 * {@see ProcessLoopTest} drives the plain loop through. Recursive composition, end to end:
 *
 *  - instantiating the campaign auto-advances into its `review` subprocess state, which starts a
 *    `publish_post` child and drives IT to its own `review_gate` — the human resolves only that
 *    LEAF gate (nested-gate discovery: `process_list_pending_approvals` surfaces the child's gate
 *    with zero subprocess-specific code in the tool);
 *  - granting the child gate publishes the post AND routes the outcome up so the campaign reaches
 *    its own terminal `done`, all in one `process_submit_decision` call;
 *  - a FRESH {@see FileEventStore} over the same append-only log reconstructs BOTH the campaign's
 *    `done` and the child's `published` — event-sourced THROUGH the recursion, no in-memory
 *    shortcut;
 *  - rejecting the child gate keeps the campaign WAITING at `review` (the subprocess never
 *    finished, so nothing routes up) with a fresh child gate re-opened.
 *
 * Every generic-engine assertion (correlation, routing, cycle/depth guards) lives in
 * `packages/milpa-orchestrator`'s own suite — this test only asserts what is DOMAIN: that
 * publish_campaign, wired onto the packages exactly as {@see
 * \Milpa\ExampleBlog\Plugins\AgentToolsPlugin\AgentToolsPlugin} wires it, drives the nested loop.
 */
final class CampaignLoopTest extends TestCase
{
    private string $path;

    private InMemoryPostStorage $posts;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/campaign-loop-' . uniqid('', true) . '.jsonl';
        $this->posts = new InMemoryPostStorage();
        $this->posts->save(new Post(1, 'Campaign post', 'Body under review for the campaign.', 'draft', '2026-01-01T00:00:00+00:00', null));
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    /**
     * Wires the example's domain (BOTH the publish_campaign parent and the publish_post child, the
     * post decision surface, the terminal listener) onto the package engine exactly as {@see
     * \Milpa\ExampleBlog\Plugins\AgentToolsPlugin\AgentToolsPlugin} does at boot — the runner is
     * given the registry so it can resolve the subprocess state onto the child at runtime.
     *
     * @return array{0: ProcessInstantiateTool, 1: ProcessListPendingApprovalsTool, 2: ProcessSubmitDecisionTool, 3: FileEventStore}
     */
    private function tools(): array
    {
        $store = new FileEventStore($this->path);

        $registry = new ProcessDefinitionRegistry();
        $registry->register(PublishPostProcess::NAME, PublishPostProcess::build());
        $registry->register(PublishCampaignProcess::NAME, PublishCampaignProcess::build());

        $dispatcher = new EventDispatcher(new NullLogger());
        $dispatcher->subscribe(
            'process.terminal',
            [new PublishPostTerminalListener($this->posts), 'onProcessTerminal'],
        );

        $gate = new HumanGate(new PostDecisionArtifactFactory($this->posts));
        $runner = new ProcessRunner($dispatcher, $registry);

        $instantiate = new ProcessInstantiateTool($store, $gate, $runner, $registry);
        $instantiate->setCurrentContext(ToolContext::cli());

        $list = new ProcessListPendingApprovalsTool($store, $gate, $registry);

        $submit = new ProcessSubmitDecisionTool($store, $gate, $runner, $registry);

        return [$instantiate, $list, $submit, $store];
    }

    public function testInstantiatingTheCampaignSurfacesTheNestedChildGate(): void
    {
        [$instantiate, $list] = $this->tools();

        $result = $instantiate->instantiate(PublishCampaignProcess::NAME, ['post_id' => 1]);

        $this->assertTrue($result->success);
        // The campaign stops AT its subprocess state — it never auto-advances past `review`.
        $this->assertSame(PublishCampaignProcess::STATE_REVIEW, $result->data['current_state']);
        $campaignId = $result->data['instance_id'];

        // Nested-gate discovery: the pending gate belongs to the CHILD publish_post instance, not
        // the campaign — even though only the campaign was ever named to process_instantiate.
        $pending = $list->list()->data['pending'];
        $this->assertCount(1, $pending);
        $this->assertNotSame($campaignId, $pending[0]['instance_id'], 'the pending gate must belong to the nested publish_post child, not the campaign');
        $options = $pending[0]['options'];
        sort($options);
        $this->assertSame(['grant', 'reject'], $options);
        // The child's decision surface carries the underlying post's title — the leaf gate is a
        // real publish_post review, unchanged by being nested inside a campaign.
        $this->assertSame('Campaign post', $pending[0]['artifact']['data']['title']);
    }

    public function testGrantingTheChildGateDrivesTheCampaignToDoneAndReplaysBothTerminalStates(): void
    {
        [$instantiate, $list, $submit] = $this->tools();

        $campaignId = $instantiate->instantiate(PublishCampaignProcess::NAME, ['post_id' => 1])->data['instance_id'];
        $child = $list->list()->data['pending'][0];
        $childId = $child['instance_id'];

        // The human resolves ONLY the child's leaf gate. 'human:editor' differs from the 'cli'
        // requester process_instantiate recorded for the whole chain, so no self-approval.
        $result = $submit->submit($childId, $child['gate_id'], 'grant', 'human:editor');

        $this->assertTrue($result->success);
        // submit() acts on the CHILD, so its returned state is the child's terminal `published`.
        $this->assertSame(PublishPostProcess::STATE_PUBLISHED, $result->data['current_state']);

        // Routing back to the parent happened INSIDE this same submit() call — nothing is pending
        // anywhere anymore, because the campaign reached its own terminal state too.
        $this->assertCount(0, $list->list()->data['pending']);

        // The both-states-via-replay proof: a FRESH store over the SAME file reconstructs BOTH the
        // campaign's `done` and the child's `published` — event-sourced through the recursion.
        $freshStore = new FileEventStore($this->path);
        $campaign = new ProcessInstance($campaignId, PublishCampaignProcess::build());
        $childInstance = new ProcessInstance($childId, PublishPostProcess::build());
        $this->assertSame(PublishCampaignProcess::STATE_DONE, $campaign->currentState($freshStore));
        $this->assertSame(PublishPostProcess::STATE_PUBLISHED, $childInstance->currentState($freshStore));

        // The child's terminal seam still fired: the underlying post is genuinely published (the
        // campaign's own terminal seam is a no-op for the post — final_state 'done' != 'published').
        $post = $this->posts->find(1);
        $this->assertNotNull($post);
        $this->assertSame('published', $post->status);
    }

    public function testRejectingTheChildGateKeepsTheCampaignWaiting(): void
    {
        [$instantiate, $list, $submit, $store] = $this->tools();

        $campaignId = $instantiate->instantiate(PublishCampaignProcess::NAME, ['post_id' => 1])->data['instance_id'];
        $child = $list->list()->data['pending'][0];
        $childId = $child['instance_id'];

        $result = $submit->submit($childId, $child['gate_id'], 'reject', 'human:editor');

        $this->assertTrue($result->success);
        // The child loops back and re-opens a fresh review_gate — the revise-and-resubmit loop,
        // exactly as in the plain publish_post process; the campaign is untouched by it.
        $this->assertSame(PublishPostProcess::STATE_REVIEW_GATE, $result->data['current_state']);

        // The subprocess never reached terminal, so no subprocess_done routed up: the campaign is
        // STILL waiting at its `review` subprocess state, reconstructed from a fresh store.
        $freshStore = new FileEventStore($this->path);
        $campaign = new ProcessInstance($campaignId, PublishCampaignProcess::build());
        $this->assertSame(PublishCampaignProcess::STATE_REVIEW, $campaign->currentState($freshStore));

        // A fresh child gate is pending again (still the child's, not the campaign's), and the
        // post was never published.
        $pendingAgain = $list->list()->data['pending'];
        $this->assertCount(1, $pendingAgain);
        $this->assertSame($childId, $pendingAgain[0]['instance_id']);
        $post = $this->posts->find(1);
        $this->assertNotNull($post);
        $this->assertSame('draft', $post->status);

        // The campaign started its child EXACTLY once — rejecting a leaf gate must never spawn a
        // second subprocess (idempotency of the SubprocessStarted marker).
        $started = array_values(array_filter(
            $store->replay($campaignId),
            static fn (\Milpa\EventStore\Event $event): bool => $event->type === 'SubprocessStarted',
        ));
        $this->assertCount(1, $started);
    }
}
