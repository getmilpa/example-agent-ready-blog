<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\App;

use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;

/**
 * Contract-faithful in-memory dispatcher: priority ordering, the documented
 * wildcard grammar (`*` matches exactly one dot-segment, anchored,
 * case-sensitive) and handler error-isolation. The published contract says
 * `async` means "dispatch via queue for deferred execution" — this example
 * has no queue, so `async` runs synchronously. A deliberate simplification,
 * not a contract-compliant deferral; noted in notes/dx-friction.md.
 */
final class EventDispatcher implements MilpaEventDispatcherInterface
{
    /** @var array<string, list<array{priority: int, handler: callable}>> */
    private array $subscribers = [];

    public function dispatch(string $eventName, array $payload = [], bool $async = false): void
    {
        foreach ($this->matchingSubscriptions($eventName) as $entry) {
            try {
                ($entry['handler'])($eventName, $payload);
            } catch (\Throwable) {
                // Error isolation per the contract: one failing handler never
                // silences the rest. A real host would log this.
            }
        }
    }

    public function subscribe(string $eventName, callable $handler, int $priority = 0): void
    {
        $this->subscribers[$eventName][] = ['priority' => $priority, 'handler' => $handler];
    }

    public function getSubscribers(string $eventName): array
    {
        return array_map(
            static fn (array $entry): callable => $entry['handler'],
            $this->matchingSubscriptions($eventName)
        );
    }

    public function hasSubscribers(string $eventName): bool
    {
        return $this->matchingSubscriptions($eventName) !== [];
    }

    /**
     * @return list<array{priority: int, handler: callable}> Exact plus wildcard
     *                                                       matches, sorted by descending priority
     */
    private function matchingSubscriptions(string $eventName): array
    {
        $matching = [];
        foreach ($this->subscribers as $pattern => $entries) {
            if ($this->matches($pattern, $eventName)) {
                foreach ($entries as $entry) {
                    $matching[] = $entry;
                }
            }
        }
        usort($matching, static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);

        return $matching;
    }

    /** `*` = exactly one segment (never spans a dot); whole-name, case-sensitive match. */
    private function matches(string $pattern, string $eventName): bool
    {
        if ($pattern === $eventName) {
            return true;
        }
        if (!str_contains($pattern, '*')) {
            return false;
        }
        $regex = '/^' . str_replace('\*', '[^.]+', preg_quote($pattern, '/')) . '$/';

        return preg_match($regex, $eventName) === 1;
    }
}
