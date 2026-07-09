<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Tests\Orchestrator;

use Milpa\Data\InMemoryRepository;
use Milpa\Eventing\EventDispatcher;
use Milpa\EventStore\Event;
use Milpa\EventStore\FileEventStore;
use Milpa\ExampleBlog\Blog\Post;
use Milpa\ExampleBlog\Orchestrator\Definitions\PublishPostProcess;
use Milpa\ExampleBlog\Orchestrator\PostDecisionArtifactFactory;
use Milpa\ExampleBlog\Orchestrator\PublishPostTerminalListener;
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
 * The dogfood: drives the whole `publish_post` process loop through the 3 `milpa/orchestrator`
 * tools — the exact shape `bin/process.php` and a real MCP client both use — end to end, proving
 * the example's DOMAIN wiring (the {@see PublishPostProcess} definition, the {@see
 * PostDecisionArtifactFactory} decision surface, the {@see PublishPostTerminalListener} terminal
 * effect) drives the package engine correctly. Instantiate auto-advances to the gate, list shows
 * the pending decision, submit auto-advances again, and it is genuinely event-sourced (a FRESH
 * {@see FileEventStore} over the same file reconstructs the same state, no in-memory shortcut).
 *
 * Every generic-engine assertion (the reducer, the store, the human gate's self-approval, ...) now
 * lives in `packages/milpa-orchestrator`'s + `packages/milpa-event-store`'s own suites — this test
 * only asserts what is domain: that publish_post, wired onto those packages, still runs the loop.
 */
final class ProcessLoopTest extends TestCase
{
    private string $path;

    /** @var InMemoryRepository<Post> */
    private InMemoryRepository $posts;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/process-loop-' . uniqid() . '.jsonl';
        $this->posts = new InMemoryRepository(Post::class);
        $this->posts->save(new Post(1, 'Process loop post', 'Body under review.', 'draft', '2026-01-01T00:00:00+00:00', null));
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    /**
     * Wires the example's domain (publish_post definition, post decision surface, terminal
     * listener) onto the package engine exactly as {@see
     * \Milpa\ExampleBlog\Plugins\AgentToolsPlugin\AgentToolsPlugin} does at boot.
     *
     * @return array{0: ProcessInstantiateTool, 1: ProcessListPendingApprovalsTool, 2: ProcessSubmitDecisionTool, 3: FileEventStore}
     */
    private function tools(): array
    {
        $store = new FileEventStore($this->path);

        $registry = new ProcessDefinitionRegistry();
        $registry->register(PublishPostProcess::NAME, PublishPostProcess::build());

        $dispatcher = new EventDispatcher(new NullLogger());
        $dispatcher->subscribe(
            'process.terminal',
            [new PublishPostTerminalListener($this->posts), 'onProcessTerminal'],
        );

        $gate = new HumanGate(new PostDecisionArtifactFactory($this->posts));
        $runner = new ProcessRunner($dispatcher);

        $instantiate = new ProcessInstantiateTool($store, $gate, $runner, $registry);
        $instantiate->setCurrentContext(ToolContext::cli());

        $list = new ProcessListPendingApprovalsTool($store, $gate, $registry);

        $submit = new ProcessSubmitDecisionTool($store, $gate, $runner, $registry);

        return [$instantiate, $list, $submit, $store];
    }

    public function testInstantiateAutoAdvancesAllTheWayToTheReviewGate(): void
    {
        [$instantiate] = $this->tools();

        $result = $instantiate->instantiate(PublishPostProcess::NAME, ['post_id' => 1]);

        $this->assertTrue($result->success);
        $this->assertNotEmpty($result->data['instance_id']);
        $this->assertSame('review_gate', $result->data['current_state']);
    }

    public function testInstantiateWithAnUnknownDefinitionIsAClearError(): void
    {
        [$instantiate] = $this->tools();

        $result = $instantiate->instantiate('not_a_real_process', []);

        $this->assertFalse($result->success);
        $this->assertSame('UNKNOWN_DEFINITION', $result->error);
    }

    public function testListPendingApprovalsShowsTheOpenGateWithItsOptions(): void
    {
        [$instantiate, $list] = $this->tools();
        $instanceId = $instantiate->instantiate(PublishPostProcess::NAME, ['post_id' => 1])->data['instance_id'];

        $result = $list->list();

        $this->assertTrue($result->success);
        $this->assertCount(1, $result->data['pending']);
        $row = $result->data['pending'][0];
        $this->assertSame($instanceId, $row['instance_id']);
        $options = $row['options'];
        sort($options);
        $this->assertSame(['grant', 'reject'], $options);
        // The package tool returns the mounted {component, data} snapshot (not pre-rendered markup);
        // the decision surface the example built carries the post's title in its data.
        $this->assertSame('Process loop post', $row['artifact']['data']['title']);
    }

    public function testListingPendingApprovalsTwiceDoesNotReopenTheGate(): void
    {
        [$instantiate, $list, , $store] = $this->tools();
        $instanceId = $instantiate->instantiate(PublishPostProcess::NAME, ['post_id' => 1])->data['instance_id'];

        $list->list();
        $list->list();

        $opened = array_values(array_filter(
            $store->replay($instanceId),
            static fn (Event $event): bool => $event->type === 'GateOpened',
        ));
        $this->assertCount(1, $opened, 'listing pending approvals must not append a redundant GateOpened event');
    }

    public function testSubmitDecisionGrantAdvancesToPublishedAndReplaysCleanFromAFreshStore(): void
    {
        [$instantiate, $list, $submit] = $this->tools();
        $instanceId = $instantiate->instantiate(PublishPostProcess::NAME, ['post_id' => 1])->data['instance_id'];
        $gateId = $list->list()->data['pending'][0]['gate_id'];

        $result = $submit->submit($instanceId, $gateId, 'grant', 'human:editor');

        $this->assertTrue($result->success);
        $this->assertSame('published', $result->data['current_state']);

        // Event-sourced end-to-end: a FRESH FileEventStore + a FRESH ProcessInstance handle over
        // the SAME file reconstructs the exact same state — nothing here is cached in memory.
        $freshStore = new FileEventStore($this->path);
        $attached = new ProcessInstance($instanceId, PublishPostProcess::build());
        $this->assertSame('published', $attached->currentState($freshStore));
    }

    public function testSubmitDecisionGrantAlsoPublishesTheUnderlyingPostViaTheTerminalListener(): void
    {
        [$instantiate, $list, $submit] = $this->tools();
        $instantiate->instantiate(PublishPostProcess::NAME, ['post_id' => 1]);
        $gateId = $list->list()->data['pending'][0]['gate_id'];
        $instanceId = $list->list()->data['pending'][0]['instance_id'];

        $beforeGrant = $this->posts->find(1);
        $this->assertNotNull($beforeGrant);
        $this->assertSame('draft', $beforeGrant->status);

        // The package tool touches no domain entity — it is the `process.terminal` event
        // PublishPostTerminalListener subscribes to (finding #4) that publishes the post.
        $submit->submit($instanceId, $gateId, 'grant', 'human:editor');

        $afterGrant = $this->posts->find(1);
        $this->assertNotNull($afterGrant);
        $this->assertSame('published', $afterGrant->status);
    }

    public function testSubmitDecisionRejectReturnsToAFreshReviewGate(): void
    {
        [$instantiate, $list, $submit] = $this->tools();
        $instanceId = $instantiate->instantiate(PublishPostProcess::NAME, ['post_id' => 1])->data['instance_id'];
        $gateId = $list->list()->data['pending'][0]['gate_id'];

        $result = $submit->submit($instanceId, $gateId, 'reject', 'human:editor');

        $this->assertTrue($result->success);
        // ProcessRunner drives draft --submit--> review_gate again and opens a fresh gate — the
        // revise-and-resubmit loop, all within this one process_submit_decision call.
        $this->assertSame('review_gate', $result->data['current_state']);

        $pendingAgain = $list->list()->data['pending'];
        $this->assertCount(1, $pendingAgain);
        $this->assertSame($instanceId, $pendingAgain[0]['instance_id']);
    }

    public function testSubmitDecisionSelfApprovalIsRejectedCleanly(): void
    {
        [$instantiate, $list, $submit] = $this->tools();
        // ProcessInstantiateTool records ToolContext::cli()'s principal ('cli') as the requester.
        $instanceId = $instantiate->instantiate(PublishPostProcess::NAME, ['post_id' => 1])->data['instance_id'];
        $gateId = $list->list()->data['pending'][0]['gate_id'];

        $result = $submit->submit($instanceId, $gateId, 'grant', 'cli');

        $this->assertFalse($result->success);
        $this->assertSame('SELF_APPROVAL_FORBIDDEN', $result->error);
    }

    public function testSubmitDecisionWithAnUnknownGateIsAClearError(): void
    {
        [$instantiate, , $submit] = $this->tools();
        $instanceId = $instantiate->instantiate(PublishPostProcess::NAME, ['post_id' => 1])->data['instance_id'];

        $result = $submit->submit($instanceId, 'never_opened_gate', 'grant', 'human:editor');

        $this->assertFalse($result->success);
        $this->assertSame('GATE_NOT_PENDING', $result->error);
    }
}
