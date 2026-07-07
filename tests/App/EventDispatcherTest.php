<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Tests\App;

use Milpa\ExampleBlog\App\EventDispatcher;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use PHPUnit\Framework\TestCase;

final class EventDispatcherTest extends TestCase
{
    public function testImplementsThePublishedContract(): void
    {
        $this->assertInstanceOf(MilpaEventDispatcherInterface::class, new EventDispatcher());
    }

    public function testDispatchInPriorityOrderWithPayload(): void
    {
        $d = new EventDispatcher();
        $seen = [];
        $d->subscribe('post.created', function (string $e, array $p) use (&$seen): void {
            $seen[] = 'low:' . $p['id'];
        }, 0);
        $d->subscribe('post.created', function (string $e, array $p) use (&$seen): void {
            $seen[] = 'high:' . $p['id'];
        }, 10);
        $d->dispatch('post.created', ['id' => 7]);
        $this->assertSame(['high:7', 'low:7'], $seen);
    }

    public function testWildcardMatchesExactlyOneSegment(): void
    {
        $d = new EventDispatcher();
        $seen = [];
        $d->subscribe('verification.*', function (string $e) use (&$seen): void {
            $seen[] = $e;
        });
        $d->dispatch('verification.granted');
        $d->dispatch('verification.requested');
        $d->dispatch('verification.audit.saved'); // dos segmentos tras el punto: NO matchea
        $this->assertSame(['verification.granted', 'verification.requested'], $seen);
    }

    public function testHandlerExceptionDoesNotStopOtherHandlers(): void
    {
        $d = new EventDispatcher();
        $ran = false;
        $d->subscribe('boom', function (): void {
            throw new \RuntimeException('handler died');
        }, 10);
        $d->subscribe('boom', function () use (&$ran): void {
            $ran = true;
        }, 0);
        $d->dispatch('boom');
        $this->assertTrue($ran);
    }

    public function testIntrospection(): void
    {
        $d = new EventDispatcher();
        $this->assertFalse($d->hasSubscribers('x'));
        $d->subscribe('x', static fn () => null);
        $this->assertTrue($d->hasSubscribers('x'));
        $this->assertCount(1, $d->getSubscribers('x'));
    }

    public function testHasSubscribersIsWildcardAware(): void
    {
        $d = new EventDispatcher();
        $this->assertFalse($d->hasSubscribers('verification.granted'));
        $d->subscribe('verification.*', static fn () => null);
        $this->assertTrue($d->hasSubscribers('verification.granted'));
        $this->assertFalse($d->hasSubscribers('verification.audit.saved')); // dos segmentos: no matchea
    }

    public function testGetSubscribersIncludesWildcardMatchesSortedByPriority(): void
    {
        $d = new EventDispatcher();
        $low = static function (): void {
        };
        $high = static function (): void {
        };
        $exact = static function (): void {
        };
        $d->subscribe('verification.*', $low, 0);
        $d->subscribe('verification.*', $high, 10);
        $d->subscribe('verification.granted', $exact, 5);

        $subscribers = $d->getSubscribers('verification.granted');

        $this->assertSame([$high, $exact, $low], $subscribers);
    }
}
