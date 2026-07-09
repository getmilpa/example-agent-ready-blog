<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Orchestrator;

use Milpa\ExampleBlog\Blog\PostStorageInterface;

/**
 * Drives a process instance forward automatically until it either reaches a terminal state or a
 * state that needs a human decision. This is the piece F3's report flagged as missing: {@see
 * Reducer} only APPLIES events already in the log — something has to decide WHICH automated
 * transition event to append next, and whether a gated state's gate is already open before opening
 * it again. `ProcessRunner` is that driver, shared by the 3 MCP tools (Task 4) so
 * `process_instantiate` and `process_submit_decision` both advance a process the same way after
 * they each do their own write (starting an instance / resolving a gate).
 *
 * Every step re-reads `$instance->currentState($store)` fresh (never cached) — "state is a
 * projection", the same invariant every other Orchestrator class holds.
 */
final class ProcessRunner
{
    /**
     * Advances `$instance` for as long as its current state is automated (has no gate): appends
     * that state's single outgoing transition as the advancing {@see ProcessEvent} (payload `{}`)
     * and loops. Stops the moment the current state is either:
     *
     *  - terminal ({@see ProcessDefinition::isTerminal()}) — nothing left to drive, or
     *  - gated ({@see ProcessDefinition::gateFor()} non-null) — a human decision is needed; opens
     *    the gate via {@see HumanGate::openFor()} UNLESS {@see HumanGate::pendingFor()} already
     *    finds an unresolved `GateOpened` for it, so calling `advance()` again on an
     *    already-awaiting instance never appends a redundant `GateOpened` event.
     *
     * This drives `publish_post`'s `draft --submit--> review_gate` in one call (opening the gate,
     * then stopping), and after a `reject` decision, `draft --submit--> review_gate` again
     * (re-opening a fresh gate, then stopping) — see the class-level docblock.
     *
     * @param string $requester the principal {@see HumanGate::openFor()} should record as the
     *                          requester of any gate this call opens
     */
    public function advance(
        EventStore $store,
        ProcessInstance $instance,
        HumanGate $gate,
        PostStorageInterface $postLookup,
        string $requester,
    ): void {
        $definition = $instance->definition;

        while (true) {
            $state = $instance->currentState($store);

            if ($definition->isTerminal($state)) {
                return;
            }

            if ($definition->gateFor($state) !== null) {
                if ($gate->pendingFor($store, $instance, $postLookup) === null) {
                    $gate->openFor($store, $instance, $requester, $postLookup);
                }

                return;
            }

            $transitions = $definition->transitionsFrom($state);
            if ($transitions === []) {
                // Defensive: a non-terminal, ungated state with no outgoing transition is a dead
                // end this slice's single definition never produces, but looping forever (or
                // throwing) would both be worse than simply stopping here.
                return;
            }

            $store->append(new ProcessEvent($instance->instanceId, $transitions[0]['name'], [], $store->nextSeq()));
        }
    }
}
