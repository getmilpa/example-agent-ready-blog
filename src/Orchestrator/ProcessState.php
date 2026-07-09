<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Orchestrator;

/**
 * The projection a {@see Reducer} folds a process instance's events into: the state it currently
 * occupies, and the accumulated context (e.g. `post_id`) carried by those events. Never stored —
 * always recomputed from the log, so two instances folding the same events always agree.
 */
final readonly class ProcessState
{
    /**
     * @param string              $currentState the state name this projection landed on
     * @param array<string,mixed> $context      accumulated event payload data (later keys override earlier ones)
     */
    public function __construct(
        public string $currentState,
        public array $context,
    ) {
    }
}
