<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Tests\Orchestrator;

use Milpa\ExampleBlog\Orchestrator\Definitions\PublishPostProcess;
use Milpa\ExampleBlog\Orchestrator\EventStore;
use Milpa\ExampleBlog\Orchestrator\ProcessEvent;
use Milpa\ExampleBlog\Orchestrator\ProcessInstance;
use PHPUnit\Framework\TestCase;

final class ProcessInstanceTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/process-instance-' . uniqid() . '.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testStartYieldsTheInitialStateAndThePostIdInContext(): void
    {
        $store = new EventStore($this->path);
        $definition = PublishPostProcess::build();

        $instance = ProcessInstance::start($store, $definition, ['post_id' => 1], 'proc-1');

        $this->assertSame('proc-1', $instance->instanceId);
        $this->assertSame('draft', $instance->currentState($store));
        $this->assertSame(1, $instance->context($store)['post_id']);
    }

    public function testStartAppendsProcessStartedThenStateEnteredToTheStore(): void
    {
        $store = new EventStore($this->path);
        $definition = PublishPostProcess::build();

        ProcessInstance::start($store, $definition, ['post_id' => 1], 'proc-2');

        $events = $store->replay('proc-2');

        $this->assertCount(2, $events);
        $this->assertSame('ProcessStarted', $events[0]->type);
        $this->assertSame(['post_id' => 1], $events[0]->payload);
        $this->assertSame('StateEntered', $events[1]->type);
        $this->assertSame(['state' => 'draft'], $events[1]->payload);
    }

    public function testAFreshReplayThroughAnIndependentInstanceHandleReconstructsTheSameState(): void
    {
        $store = new EventStore($this->path);
        $definition = PublishPostProcess::build();

        $original = ProcessInstance::start($store, $definition, ['post_id' => 7], 'proc-3');
        $store->append(new ProcessEvent('proc-3', 'submit', [], $store->nextSeq()));

        // A brand-new EventStore over the same file, and a brand-new ProcessInstance handle
        // built WITHOUT calling start() again — proving state is a projection recomputed from
        // the log, never a field either object carries.
        $freshStore = new EventStore($this->path);
        $attached = new ProcessInstance('proc-3', $definition);

        $this->assertSame('review_gate', $attached->currentState($freshStore));
        $this->assertSame($original->currentState($freshStore), $attached->currentState($freshStore));
    }

    public function testStartGeneratesAnInstanceIdWhenNoneIsGiven(): void
    {
        $store = new EventStore($this->path);
        $definition = PublishPostProcess::build();

        $instance = ProcessInstance::start($store, $definition, ['post_id' => 1]);

        $this->assertNotSame('', $instance->instanceId);
        $this->assertSame('draft', $instance->currentState($store));
    }

    public function testTwoStartedInstancesDoNotShareState(): void
    {
        $store = new EventStore($this->path);
        $definition = PublishPostProcess::build();

        $one = ProcessInstance::start($store, $definition, ['post_id' => 1], 'proc-a');
        $two = ProcessInstance::start($store, $definition, ['post_id' => 2], 'proc-b');
        $store->append(new ProcessEvent('proc-a', 'submit', [], $store->nextSeq()));

        $this->assertSame('review_gate', $one->currentState($store));
        $this->assertSame('draft', $two->currentState($store));
        $this->assertSame(2, $two->context($store)['post_id']);
    }
}
