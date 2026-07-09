<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Tests\Orchestrator;

use Milpa\ExampleBlog\Orchestrator\EventStore;
use Milpa\ExampleBlog\Orchestrator\ProcessEvent;
use PHPUnit\Framework\TestCase;

final class EventStoreTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/events-' . uniqid() . '.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testReplayReturnsOnlyTheGivenInstancesEventsInSeqOrder(): void
    {
        $store = new EventStore($this->path);

        $store->append(new ProcessEvent('A', 'ProcessStarted', ['post_id' => 1], $store->nextSeq()));
        $store->append(new ProcessEvent('B', 'ProcessStarted', ['post_id' => 2], $store->nextSeq()));
        $store->append(new ProcessEvent('A', 'submit', [], $store->nextSeq()));
        $store->append(new ProcessEvent('B', 'submit', [], $store->nextSeq()));
        $store->append(new ProcessEvent('A', 'grant', [], $store->nextSeq()));

        $eventsA = $store->replay('A');

        $this->assertCount(3, $eventsA);
        $this->assertSame(['ProcessStarted', 'submit', 'grant'], array_map(static fn (ProcessEvent $e): string => $e->type, $eventsA));
        $this->assertSame([1, 3, 5], array_map(static fn (ProcessEvent $e): int => $e->seq, $eventsA));
        foreach ($eventsA as $event) {
            $this->assertSame('A', $event->instanceId);
        }
    }

    public function testTheLogFileHasOneLinePerAppendedEvent(): void
    {
        $store = new EventStore($this->path);

        $store->append(new ProcessEvent('A', 'ProcessStarted', ['post_id' => 1], $store->nextSeq()));
        $store->append(new ProcessEvent('B', 'ProcessStarted', ['post_id' => 2], $store->nextSeq()));
        $store->append(new ProcessEvent('A', 'submit', [], $store->nextSeq()));
        $store->append(new ProcessEvent('B', 'submit', [], $store->nextSeq()));
        $store->append(new ProcessEvent('A', 'grant', [], $store->nextSeq()));

        $lines = array_filter(explode("\n", (string) file_get_contents($this->path)), static fn (string $l): bool => trim($l) !== '');

        $this->assertCount(5, $lines);
    }

    public function testAFreshEventStoreOverTheSameFileReplaysIdentically(): void
    {
        $store = new EventStore($this->path);
        $store->append(new ProcessEvent('A', 'ProcessStarted', ['post_id' => 1], $store->nextSeq()));
        $store->append(new ProcessEvent('A', 'submit', [], $store->nextSeq()));

        $original = $store->replay('A');

        $fresh = new EventStore($this->path);
        $replayed = $fresh->replay('A');

        $this->assertEquals($original, $replayed);
        $this->assertSame(3, $fresh->nextSeq(), 'nextSeq must continue from the persisted log, not reset');
    }

    public function testNextSeqIsOneForAMissingLog(): void
    {
        $store = new EventStore($this->path);

        $this->assertSame(1, $store->nextSeq());
    }

    public function testReplayOfAnUnknownInstanceIsEmpty(): void
    {
        $store = new EventStore($this->path);
        $store->append(new ProcessEvent('A', 'ProcessStarted', [], $store->nextSeq()));

        $this->assertSame([], $store->replay('does-not-exist'));
    }
}
