<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Tests\Orchestrator;

use Milpa\Data\InMemoryRepository;
use Milpa\Eventing\EventDispatcher;
use Milpa\EventStore\Event;
use Milpa\EventStore\FileEventStore;
use Milpa\ExampleBlog\Blog\Post;
use Milpa\ExampleBlog\Identity\ApproverKeyring;
use Milpa\ExampleBlog\Identity\EditorKey;
use Milpa\ExampleBlog\Identity\SignedCallDesk;
use Milpa\ExampleBlog\Orchestrator\Definitions\PublishPostProcess;
use Milpa\ExampleBlog\Orchestrator\PostDecisionArtifactFactory;
use Milpa\ExampleBlog\Orchestrator\PublishPostTerminalListener;
use Milpa\ExampleBlog\Tests\Support\Sandbox;
use Milpa\Orchestrator\HumanGate;
use Milpa\Orchestrator\ProcessDefinitionRegistry;
use Milpa\Orchestrator\ProcessInstance;
use Milpa\Orchestrator\ProcessRunner;
use Milpa\Orchestrator\Tools\ProcessInstantiateTool;
use Milpa\Orchestrator\Tools\ProcessListPendingApprovalsTool;
use Milpa\Orchestrator\Tools\ProcessSubmitDecisionTool;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Identity\FileNonceLedger;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ToolRuntime\ToolResult;
use Milpa\ToolRuntime\ToolScanner;
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
 * Two parties drive it, as in the demo. The AGENT starts the process under {@see ToolContext::cli()}
 * — `local-shell`, an unsigned terminal. A PERSON answers the gate: an editor with a key of their
 * own, enrolled with the house, who signs each decision and presents it at the {@see SignedCallDesk}
 * ({@see self::decide()}). No test here hands the engine an approver's name.
 *
 * Every generic-engine assertion (the reducer, the store, the human gate's self-approval, ...) now
 * lives in `packages/milpa-orchestrator`'s + `packages/milpa-event-store`'s own suites — this test
 * only asserts what is domain: that publish_post, wired onto those packages, still runs the loop.
 */
final class ProcessLoopTest extends TestCase
{
    private Sandbox $sandbox;

    private string $path;

    private EditorKey $editor;

    private SignedCallDesk $desk;

    /** @var InMemoryRepository<Post> */
    private InMemoryRepository $posts;

    protected function setUp(): void
    {
        $this->sandbox = Sandbox::create();
        $this->path = $this->sandbox->events();
        $this->editor = $this->sandbox->editor();
        $this->posts = new InMemoryRepository(Post::class);
        $this->posts->save(new Post(1, 'Process loop post', 'Body under review.', 'draft', '2026-01-01T00:00:00+00:00', null));
    }

    protected function tearDown(): void
    {
        $this->sandbox->destroy();
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

        // The desk a person's signed decision enters through, over a registry holding these same
        // tools — the plugin scans them into the kernel's registry the same way.
        $tools = new ToolRegistry(new NullLogger());
        $scanner = new ToolScanner($tools);
        $scanner->scan($instantiate);
        $scanner->scan($list);
        $scanner->scan($submit);
        $this->desk = new SignedCallDesk(
            $tools,
            new ApproverKeyring($this->sandbox->identity() . '/approvers'),
            new FileNonceLedger($this->sandbox->identity() . '/spent'),
            'process-loop-test',
        );

        return [$instantiate, $list, $submit, $store];
    }

    /** The editor signs this one decision and presents it at the desk — the only way a gate is answered. */
    private function decide(string $instanceId, string $gateId, string $decision): ToolResult
    {
        $outcome = Sandbox::signedCall($this->desk, $this->editor, 'process_submit_decision', [
            'instance_id' => $instanceId,
            'gate_id' => $gateId,
            'decision' => $decision,
        ]);
        $this->assertTrue($outcome->verdict->granted, (string) $outcome->verdict->reason);
        $this->assertNotNull($outcome->result);

        return $outcome->result;
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

    public function testACallerNobodyAuthenticatedStartsNothing(): void
    {
        [$instantiate, $list] = $this->tools();
        // tools() handed the tool the agent's context once; the tool drops it after every call, so
        // one caller's identity never answers for the next. This second call arrives as nobody.
        $instantiate->instantiate(PublishPostProcess::NAME, ['post_id' => 1]);

        $result = $instantiate->instantiate(PublishPostProcess::NAME, ['post_id' => 1]);

        $this->assertFalse($result->success);
        $this->assertSame('UNAUTHENTICATED', $result->error);
        $this->assertCount(1, $list->list()->data['pending']);
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
        [$instantiate, $list] = $this->tools();
        $instanceId = $instantiate->instantiate(PublishPostProcess::NAME, ['post_id' => 1])->data['instance_id'];
        $gateId = $list->list()->data['pending'][0]['gate_id'];

        $result = $this->decide($instanceId, $gateId, 'grant');

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
        [$instantiate, $list] = $this->tools();
        $instantiate->instantiate(PublishPostProcess::NAME, ['post_id' => 1]);
        $gateId = $list->list()->data['pending'][0]['gate_id'];
        $instanceId = $list->list()->data['pending'][0]['instance_id'];

        $beforeGrant = $this->posts->find(1);
        $this->assertNotNull($beforeGrant);
        $this->assertSame('draft', $beforeGrant->status);

        // The package tool touches no domain entity — it is the `process.terminal` event
        // PublishPostTerminalListener subscribes to (finding #4) that publishes the post.
        $this->decide($instanceId, $gateId, 'grant');

        $afterGrant = $this->posts->find(1);
        $this->assertNotNull($afterGrant);
        $this->assertSame('published', $afterGrant->status);
    }

    public function testSubmitDecisionRejectReturnsToAFreshReviewGate(): void
    {
        [$instantiate, $list] = $this->tools();
        $instanceId = $instantiate->instantiate(PublishPostProcess::NAME, ['post_id' => 1])->data['instance_id'];
        $gateId = $list->list()->data['pending'][0]['gate_id'];

        $result = $this->decide($instanceId, $gateId, 'reject');

        $this->assertTrue($result->success);
        // ProcessRunner drives draft --submit--> review_gate again and opens a fresh gate — the
        // revise-and-resubmit loop, all within this one process_submit_decision call.
        $this->assertSame('review_gate', $result->data['current_state']);

        $pendingAgain = $list->list()->data['pending'];
        $this->assertCount(1, $pendingAgain);
        $this->assertSame($instanceId, $pendingAgain[0]['instance_id']);
    }

    public function testTheAgentThatOpenedTheGateCannotAnswerIt(): void
    {
        [$instantiate, $list, $submit] = $this->tools();
        $instanceId = $instantiate->instantiate(PublishPostProcess::NAME, ['post_id' => 1])->data['instance_id'];
        $gateId = $list->list()->data['pending'][0]['gate_id'];

        // The agent answers under the only identity it has: the terminal's. `process_submit_decision`
        // used to take a `principal` argument, and this test used to pass the requester's own name to
        // watch SELF_APPROVAL_FORBIDDEN — while every other test here passed 'human:editor' and
        // sailed through, which was the hole: author != approver was only as strong as the caller's
        // honesty. Since orchestrator 0.11 there is no argument to lie in, and since 0.13 a
        // transport's name ('local-shell', 'stdio') cannot answer a gate at all.
        $submit->setCurrentContext(ToolContext::cli());
        $asTheTerminal = $submit->submit($instanceId, $gateId, 'grant');

        $this->assertFalse($asTheTerminal->success);
        $this->assertSame('UNVERIFIED_APPROVER', $asTheTerminal->error);

        // And with no caller at all it fails closed rather than guessing one.
        $asNobody = $submit->submit($instanceId, $gateId, 'grant');

        $this->assertFalse($asNobody->success);
        $this->assertSame('UNAUTHENTICATED', $asNobody->error);

        $this->assertSame('draft', $this->posts->find(1)?->status);
        $this->assertCount(1, $list->list()->data['pending']);
    }

    public function testAnEditorCannotAnswerAGateTheyOpenedThemselves(): void
    {
        [, $list] = $this->tools();

        // Being verified is necessary to decide, and it is not sufficient: the editor starts this
        // process under their own signature, so the gate's requester is the editor.
        $started = Sandbox::signedCall($this->desk, $this->editor, 'process_instantiate', [
            'definition' => PublishPostProcess::NAME,
            'inputs' => ['post_id' => 1],
        ]);
        $this->assertTrue($started->result?->success);
        $gateId = $list->list()->data['pending'][0]['gate_id'];

        $result = $this->decide($started->result->data['instance_id'], $gateId, 'grant');

        $this->assertFalse($result->success);
        $this->assertSame('SELF_APPROVAL_FORBIDDEN', $result->error);
        $this->assertSame('draft', $this->posts->find(1)?->status);
    }

    public function testSubmitDecisionWithAnUnknownGateIsAClearError(): void
    {
        [$instantiate] = $this->tools();
        $instanceId = $instantiate->instantiate(PublishPostProcess::NAME, ['post_id' => 1])->data['instance_id'];

        $result = $this->decide($instanceId, 'never_opened_gate', 'grant');

        $this->assertFalse($result->success);
        $this->assertSame('GATE_NOT_PENDING', $result->error);
    }
}
