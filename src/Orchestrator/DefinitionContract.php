<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Orchestrator;

/**
 * The narrow slice of a process definition the {@see Reducer} needs to fold events into a
 * {@see ProcessState}: where a process starts, and which transitions are available from a given
 * state. Deliberately smaller than a full process definition (states, terminality, gates, inputs)
 * — a real `ProcessDefinition` composing `milpa/workflow`'s `StateDefinition`/`TransitionDefinition`
 * implements this contract on top of its richer model.
 */
interface DefinitionContract
{
    /** The state a fresh process instance starts in. */
    public function initialState(): string;

    /**
     * The transitions available from `$state`, each naming the event `type` that triggers it and
     * the state it leads to. An empty list means `$state` has no outgoing transitions (e.g. it is
     * terminal, or simply has none defined from it).
     *
     * @return list<array{name: string, to: string}>
     */
    public function transitionsFrom(string $state): array;
}
