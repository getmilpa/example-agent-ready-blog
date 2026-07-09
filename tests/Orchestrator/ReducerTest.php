<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Tests\Orchestrator;

use Milpa\ExampleBlog\Orchestrator\ProcessEvent;
use Milpa\ExampleBlog\Orchestrator\Reducer;
use Milpa\ExampleBlog\Tests\Orchestrator\Fixtures\StubDefinition;
use PHPUnit\Framework\TestCase;

final class ReducerTest extends TestCase
{
    public function testFoldingAMatchingTransitionAdvancesTheState(): void
    {
        $reducer = new Reducer();
        $definition = new StubDefinition();

        $events = [
            new ProcessEvent('A', 'StateEntered', ['state' => 'draft'], 1),
            new ProcessEvent('A', 'submit', [], 2),
        ];

        $result = $reducer->apply($events, $definition);

        $this->assertSame('review', $result->currentState);
    }

    public function testAnEventWithNoMatchingTransitionLeavesStateUnchanged(): void
    {
        $reducer = new Reducer();
        $definition = new StubDefinition();

        $events = [
            new ProcessEvent('A', 'grant', [], 1),
        ];

        $result = $reducer->apply($events, $definition);

        $this->assertSame('draft', $result->currentState, 'grant is not a transition from draft, so the state must not move');
    }

    public function testAnEmptyEventListYieldsTheInitialState(): void
    {
        $reducer = new Reducer();
        $definition = new StubDefinition();

        $result = $reducer->apply([], $definition);

        $this->assertSame('draft', $result->currentState);
        $this->assertSame([], $result->context);
    }

    public function testEventPayloadsFoldIntoContextRegardlessOfTransitionMatch(): void
    {
        $reducer = new Reducer();
        $definition = new StubDefinition();

        $events = [
            new ProcessEvent('A', 'ProcessStarted', ['post_id' => 1], 1),
            new ProcessEvent('A', 'submit', ['note' => 'ready'], 2),
        ];

        $result = $reducer->apply($events, $definition);

        $this->assertSame('review', $result->currentState);
        $this->assertSame(['post_id' => 1, 'note' => 'ready'], $result->context);
    }

    public function testReplayIsDeterministicSameEventsYieldTheSameState(): void
    {
        $reducer = new Reducer();
        $definition = new StubDefinition();

        $events = [
            new ProcessEvent('A', 'ProcessStarted', ['post_id' => 1], 1),
            new ProcessEvent('A', 'submit', [], 2),
        ];

        $first = $reducer->apply($events, $definition);
        $second = $reducer->apply($events, $definition);

        $this->assertEquals($first, $second);
    }

    public function testATransitionOnlyAppliesFromTheStateItIsDefinedOn(): void
    {
        $reducer = new Reducer();
        $definition = new StubDefinition();

        // 'submit' fires once (draft -> review); a second 'submit' has no transition from
        // 'review' in the stub, so it must be a no-op.
        $events = [
            new ProcessEvent('A', 'submit', [], 1),
            new ProcessEvent('A', 'submit', [], 2),
        ];

        $result = $reducer->apply($events, $definition);

        $this->assertSame('review', $result->currentState);
    }
}
