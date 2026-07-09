<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Tests\Orchestrator;

use Milpa\ExampleBlog\Blog\Post;
use Milpa\ExampleBlog\Orchestrator\Definitions\PublishPostProcess;
use Milpa\ExampleBlog\Orchestrator\EventStore;
use Milpa\ExampleBlog\Orchestrator\HumanGate;
use Milpa\ExampleBlog\Orchestrator\ProcessEvent;
use Milpa\ExampleBlog\Orchestrator\ProcessInstance;
use Milpa\ExampleBlog\Orchestrator\Tools\ProcessInstantiateTool;
use Milpa\ExampleBlog\Orchestrator\Tools\ProcessListPendingApprovalsTool;
use Milpa\ExampleBlog\Orchestrator\Tools\ProcessSubmitDecisionTool;
use Milpa\ExampleBlog\Tests\Orchestrator\Fixtures\InMemoryPostStorage;
use Milpa\ToolRuntime\Contracts\ToolContext;
use PHPUnit\Framework\TestCase;

/**
 * Drives the whole `publish_post` process loop through the 3 MCP tools — the exact shape
 * `bin/process.php` and a real MCP client both use — end to end: instantiate (auto-advances to the
 * gate), list the pending decision, submit a decision (auto-advances again), and prove it is
 * genuinely event-sourced (a FRESH {@see EventStore} over the same file reconstructs the same
 * state, with no in-memory shortcut).
 */
final class ProcessLoopTest extends TestCase
{
    private string $path;

    private InMemoryPostStorage $posts;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/process-loop-' . uniqid() . '.jsonl';
        $this->posts = new InMemoryPostStorage();
        $this->posts->save(new Post(1, 'Process loop post', 'Body under review.', 'draft', '2026-01-01T00:00:00+00:00', null));
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    /**
     * @return array{0: ProcessInstantiateTool, 1: ProcessListPendingApprovalsTool, 2: ProcessSubmitDecisionTool, 3: EventStore}
     */
    private function tools(): array
    {
        $store = new EventStore($this->path);
        $gate = new HumanGate();

        $instantiate = new ProcessInstantiateTool($store, $gate, $this->posts);
        $instantiate->setCurrentContext(ToolContext::cli());

        $list = new ProcessListPendingApprovalsTool($store, $gate, $this->posts);
        $list->setCurrentContext(ToolContext::cli());

        $submit = new ProcessSubmitDecisionTool($store, $gate, $this->posts);

        return [$instantiate, $list, $submit, $store];
    }

    public function testInstantiateAutoAdvancesAllTheWayToTheReviewGate(): void
    {
        [$instantiate] = $this->tools();

        $result = $instantiate->instantiate(PublishPostProcess::NAME, json_encode(['post_id' => 1], \JSON_THROW_ON_ERROR));

        $this->assertTrue($result->success);
        $this->assertNotEmpty($result->data['instance_id']);
        $this->assertSame('review_gate', $result->data['current_state']);
    }

    public function testInstantiateWithAnUnknownDefinitionIsAClearError(): void
    {
        [$instantiate] = $this->tools();

        $result = $instantiate->instantiate('not_a_real_process', '{}');

        $this->assertFalse($result->success);
        $this->assertSame('UNKNOWN_DEFINITION', $result->error);
    }

    public function testListPendingApprovalsShowsTheOpenGateWithItsOptions(): void
    {
        [$instantiate, $list] = $this->tools();
        $instanceId = $instantiate->instantiate(PublishPostProcess::NAME, json_encode(['post_id' => 1], \JSON_THROW_ON_ERROR))->data['instance_id'];

        $result = $list->list();

        $this->assertTrue($result->success);
        $this->assertCount(1, $result->data['pending']);
        $row = $result->data['pending'][0];
        $this->assertSame($instanceId, $row['instance_id']);
        $options = $row['options'];
        sort($options);
        $this->assertSame(['grant', 'reject'], $options);
        $this->assertStringContainsString('Process loop post', $row['artifact']);
    }

    public function testListingPendingApprovalsTwiceDoesNotReopenTheGate(): void
    {
        [$instantiate, $list, , $store] = $this->tools();
        $instanceId = $instantiate->instantiate(PublishPostProcess::NAME, json_encode(['post_id' => 1], \JSON_THROW_ON_ERROR))->data['instance_id'];

        $list->list();
        $list->list();

        $opened = array_values(array_filter(
            $store->replay($instanceId),
            static fn (ProcessEvent $event): bool => $event->type === 'GateOpened',
        ));
        $this->assertCount(1, $opened, 'listing pending approvals must not append a redundant GateOpened event');
    }

    public function testSubmitDecisionGrantAdvancesToPublishedAndReplaysCleanFromAFreshStore(): void
    {
        [$instantiate, $list, $submit] = $this->tools();
        $instanceId = $instantiate->instantiate(PublishPostProcess::NAME, json_encode(['post_id' => 1], \JSON_THROW_ON_ERROR))->data['instance_id'];
        $gateId = $list->list()->data['pending'][0]['gate_id'];

        $result = $submit->submit($instanceId, $gateId, 'grant', 'human:editor');

        $this->assertTrue($result->success);
        $this->assertSame('published', $result->data['current_state']);

        // Event-sourced end-to-end: a FRESH EventStore + a FRESH ProcessInstance handle over the
        // SAME file reconstructs the exact same state — nothing here is cached in memory.
        $freshStore = new EventStore($this->path);
        $attached = new ProcessInstance($instanceId, PublishPostProcess::build());
        $this->assertSame('published', $attached->currentState($freshStore));
    }

    public function testSubmitDecisionGrantAlsoPublishesTheUnderlyingPost(): void
    {
        [$instantiate, $list, $submit] = $this->tools();
        $instantiate->instantiate(PublishPostProcess::NAME, json_encode(['post_id' => 1], \JSON_THROW_ON_ERROR));
        $gateId = $list->list()->data['pending'][0]['gate_id'];
        $instanceId = $list->list()->data['pending'][0]['instance_id'];

        $beforeGrant = $this->posts->find(1);
        $this->assertNotNull($beforeGrant);
        $this->assertSame('draft', $beforeGrant->status);

        $submit->submit($instanceId, $gateId, 'grant', 'human:editor');

        $afterGrant = $this->posts->find(1);
        $this->assertNotNull($afterGrant);
        $this->assertSame('published', $afterGrant->status);
    }

    public function testSubmitDecisionRejectReturnsToAFreshReviewGate(): void
    {
        [$instantiate, $list, $submit] = $this->tools();
        $instanceId = $instantiate->instantiate(PublishPostProcess::NAME, json_encode(['post_id' => 1], \JSON_THROW_ON_ERROR))->data['instance_id'];
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
        $instanceId = $instantiate->instantiate(PublishPostProcess::NAME, json_encode(['post_id' => 1], \JSON_THROW_ON_ERROR))->data['instance_id'];
        $gateId = $list->list()->data['pending'][0]['gate_id'];

        $result = $submit->submit($instanceId, $gateId, 'grant', 'cli');

        $this->assertFalse($result->success);
        $this->assertSame('SELF_APPROVAL_FORBIDDEN', $result->error);
    }

    public function testSubmitDecisionWithAnUnknownGateIsAClearError(): void
    {
        [$instantiate, , $submit] = $this->tools();
        $instanceId = $instantiate->instantiate(PublishPostProcess::NAME, json_encode(['post_id' => 1], \JSON_THROW_ON_ERROR))->data['instance_id'];

        $result = $submit->submit($instanceId, 'never_opened_gate', 'grant', 'human:editor');

        $this->assertFalse($result->success);
        $this->assertSame('GATE_NOT_PENDING', $result->error);
    }
}
