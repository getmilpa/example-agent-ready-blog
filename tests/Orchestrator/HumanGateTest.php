<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Tests\Orchestrator;

use Milpa\ExampleBlog\Blog\Post;
use Milpa\ExampleBlog\Orchestrator\DecisionArtifact;
use Milpa\ExampleBlog\Orchestrator\Definitions\PublishPostProcess;
use Milpa\ExampleBlog\Orchestrator\EventStore;
use Milpa\ExampleBlog\Orchestrator\HumanGate;
use Milpa\ExampleBlog\Orchestrator\ProcessEvent;
use Milpa\ExampleBlog\Orchestrator\ProcessInstance;
use Milpa\ExampleBlog\Tests\Orchestrator\Fixtures\InMemoryPostStorage;
use Milpa\Workflow\Exceptions\SelfApprovalException;
use PHPUnit\Framework\TestCase;

final class HumanGateTest extends TestCase
{
    private string $path;

    private InMemoryPostStorage $posts;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/human-gate-' . uniqid() . '.jsonl';
        $this->posts = new InMemoryPostStorage();
        $this->posts->save(new Post(1, 'The post under review', 'Body text.', 'draft', '2026-01-01T00:00:00+00:00', null));
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    private function instanceAtReviewGate(EventStore $store, string $instanceId = 'proc-1'): ProcessInstance
    {
        $definition = PublishPostProcess::build();
        $instance = ProcessInstance::start($store, $definition, ['post_id' => 1], $instanceId);
        $store->append(new ProcessEvent($instanceId, 'submit', [], $store->nextSeq()));

        return $instance;
    }

    public function testOpenForAnInstanceAtReviewGateReturnsAPendingDecisionWithTheArtifact(): void
    {
        $store = new EventStore($this->path);
        $instance = $this->instanceAtReviewGate($store);

        $pending = (new HumanGate())->openFor($store, $instance, 'ana', $this->posts);

        $this->assertSame('proc-1', $pending->instanceId);
        $this->assertSame('review_gate_gate', $pending->gateId);
        $this->assertSame('editor', $pending->assignee);
        $this->assertInstanceOf(DecisionArtifact::class, $pending->artifact);

        $options = $pending->options;
        sort($options);
        $this->assertSame(['grant', 'reject'], $options);
    }

    public function testOpenForAppendsAGateOpenedEventCarryingTheRequesterAndOptions(): void
    {
        $store = new EventStore($this->path);
        $instance = $this->instanceAtReviewGate($store);

        (new HumanGate())->openFor($store, $instance, 'ana', $this->posts);

        $events = $store->replay('proc-1');
        $opened = end($events);

        $this->assertNotFalse($opened);
        $this->assertSame('GateOpened', $opened->type);
        $this->assertSame('ana', $opened->payload['requester']);

        $options = $opened->payload['options'];
        sort($options);
        $this->assertSame(['grant', 'reject'], $options);
    }

    public function testOpenForThrowsWhenTheCurrentStateHasNoGate(): void
    {
        $store = new EventStore($this->path);
        $definition = PublishPostProcess::build();
        $instance = ProcessInstance::start($store, $definition, ['post_id' => 1], 'proc-draft');

        $this->expectException(\RuntimeException::class);

        (new HumanGate())->openFor($store, $instance, 'ana', $this->posts);
    }

    public function testOpenForThrowsWhenThePostCannotBeFound(): void
    {
        $store = new EventStore($this->path);
        $definition = PublishPostProcess::build();
        $instance = ProcessInstance::start($store, $definition, ['post_id' => 999], 'proc-missing-post');
        $store->append(new ProcessEvent('proc-missing-post', 'submit', [], $store->nextSeq()));

        $this->expectException(\RuntimeException::class);

        (new HumanGate())->openFor($store, $instance, 'ana', $this->posts);
    }

    public function testResolveWithGrantAdvancesTheInstanceToPublished(): void
    {
        $store = new EventStore($this->path);
        $instance = $this->instanceAtReviewGate($store);
        $gate = new HumanGate();
        $pending = $gate->openFor($store, $instance, 'ana', $this->posts);

        $event = $gate->resolve($store, $instance, $pending->gateId, 'grant', 'ben');

        $this->assertSame('grant', $event->type);
        $this->assertSame(['by' => 'ben'], $event->payload);
        $this->assertSame('published', $instance->currentState($store));
    }

    public function testResolveWithRejectReturnsTheInstanceToDraft(): void
    {
        $store = new EventStore($this->path);
        $instance = $this->instanceAtReviewGate($store);
        $gate = new HumanGate();
        $pending = $gate->openFor($store, $instance, 'ana', $this->posts);

        $event = $gate->resolve($store, $instance, $pending->gateId, 'reject', 'ben');

        $this->assertSame('reject', $event->type);
        $this->assertSame('draft', $instance->currentState($store));
    }

    public function testResolvingWithTheSamePrincipalThatRequestedThrowsSelfApprovalException(): void
    {
        $store = new EventStore($this->path);
        $instance = $this->instanceAtReviewGate($store);
        $gate = new HumanGate();
        $pending = $gate->openFor($store, $instance, 'ana', $this->posts);

        $this->expectException(SelfApprovalException::class);

        $gate->resolve($store, $instance, $pending->gateId, 'grant', 'ana');
    }

    public function testResolvingWithAnUnknownDecisionThrows(): void
    {
        $store = new EventStore($this->path);
        $instance = $this->instanceAtReviewGate($store);
        $gate = new HumanGate();
        $pending = $gate->openFor($store, $instance, 'ana', $this->posts);

        $this->expectException(\InvalidArgumentException::class);

        $gate->resolve($store, $instance, $pending->gateId, 'delete', 'ben');
    }

    public function testResolvingAGateThatWasNeverOpenedThrows(): void
    {
        $store = new EventStore($this->path);
        $instance = $this->instanceAtReviewGate($store);
        $gate = new HumanGate();

        $this->expectException(\RuntimeException::class);

        $gate->resolve($store, $instance, 'review_gate_gate', 'grant', 'ben');
    }

    public function testResolvingAnAlreadyResolvedGateAgainThrows(): void
    {
        $store = new EventStore($this->path);
        $instance = $this->instanceAtReviewGate($store);
        $gate = new HumanGate();
        $pending = $gate->openFor($store, $instance, 'ana', $this->posts);
        $gate->resolve($store, $instance, $pending->gateId, 'grant', 'ben');

        $this->expectException(\RuntimeException::class);

        $gate->resolve($store, $instance, $pending->gateId, 'grant', 'ben');
    }

    public function testAFreshReplayAfterResolutionReconstructsThePublishedState(): void
    {
        $store = new EventStore($this->path);
        $instance = $this->instanceAtReviewGate($store);
        $gate = new HumanGate();
        $pending = $gate->openFor($store, $instance, 'ana', $this->posts);
        $gate->resolve($store, $instance, $pending->gateId, 'grant', 'ben');

        $freshStore = new EventStore($this->path);
        $attached = new ProcessInstance('proc-1', PublishPostProcess::build());

        $this->assertSame('published', $attached->currentState($freshStore));
    }
}
