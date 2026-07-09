<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Orchestrator;

use Milpa\ExampleBlog\Blog\PostStorageInterface;
use Milpa\Workflow\Exceptions\SelfApprovalException;

/**
 * Opens and resolves a process instance's gate — the event-sourced counterpart to
 * `milpa/workflow`'s Doctrine-backed {@see \Milpa\Workflow\Services\GatePassageService}.
 *
 * This slice is zero-DB (an append-only {@see EventStore}), so `GatePassageService`
 * (which `persist()`/`flush()`s a {@see \Milpa\Workflow\Entities\GatePassage} row
 * through a real `EntityManagerInterface`) is not usable here — see the F3 report's
 * Fricciones for the full writeup. `HumanGate` instead represents "the gate is open,
 * awaiting a decision" as a `GateOpened` {@see ProcessEvent} in the SAME log the
 * process instance itself replays through, and mirrors `GatePassageService::approvePassage()`'s
 * anti-self-approval rule (D9: requester and resolver must be different opaque
 * principals) by reading that `GateOpened` event back rather than a persisted
 * `GatePassage` row.
 */
final class HumanGate
{
    /**
     * Opens `$instance`'s current gate for a human decision: appends a `GateOpened`
     * event (payload: `requester` + the gate's `options`), looks up the post under
     * review via `$postLookup` (keyed by the instance's `post_id` context), and
     * returns the resulting {@see PendingDecision} built around a fresh
     * {@see DecisionArtifact}.
     *
     * @throws \RuntimeException when `$instance`'s current state has no gate, its
     *                           context carries no integer `post_id`, or that post
     *                           cannot be found via `$postLookup`
     */
    public function openFor(
        EventStore $store,
        ProcessInstance $instance,
        string $requester,
        PostStorageInterface $postLookup,
    ): PendingDecision {
        $state = $instance->currentState($store);
        $gate = $instance->definition->gateFor($state);
        if ($gate === null) {
            throw new \RuntimeException(sprintf(
                "HumanGate: instance '%s' is at state '%s', which has no gate to open.",
                $instance->instanceId,
                $state,
            ));
        }

        $postId = $instance->context($store)['post_id'] ?? null;
        if (!is_int($postId)) {
            throw new \RuntimeException(sprintf(
                "HumanGate: instance '%s' context has no integer 'post_id'.",
                $instance->instanceId,
            ));
        }

        $post = $postLookup->find($postId);
        if ($post === null) {
            throw new \RuntimeException("HumanGate: post #{$postId} not found.");
        }

        $transitions = $instance->definition->transitionsFrom($state);
        $options = array_column($transitions, 'name');
        $gateId = $gate->getCode();

        $store->append(new ProcessEvent($instance->instanceId, 'GateOpened', [
            'gate_id' => $gateId,
            'requester' => $requester,
            'options' => $options,
        ], $store->nextSeq()));

        return new PendingDecision(
            instanceId: $instance->instanceId,
            gateId: $gateId,
            assignee: $gate->getApproverRole(),
            artifact: new DecisionArtifact($post->title, $post->body, $transitions),
            options: $options,
        );
    }

    /**
     * Resolves `$gateId` for `$instance` with `$decision`: validates `$decision` is
     * one of the options the matching `GateOpened` event offered, enforces the
     * anti-self-approval invariant against that event's `requester`, then appends
     * `$decision` itself as the advancing {@see ProcessEvent} (payload: `{by:
     * $principal}`) — the SAME mechanism any trigger event uses to move the process
     * forward (see {@see Reducer}).
     *
     * @throws \RuntimeException         when `$instance` is not currently awaiting
     *                                   `$gateId` (never opened, or already resolved —
     *                                   its current state no longer carries a gate
     *                                   whose code matches `$gateId`)
     * @throws \InvalidArgumentException when `$decision` is not one of the options the
     *                                   gate was opened with
     * @throws SelfApprovalException     when `$principal` equals the requester that
     *                                   opened `$gateId` (D9: a human cannot resolve
     *                                   their own request)
     */
    public function resolve(
        EventStore $store,
        ProcessInstance $instance,
        string $gateId,
        string $decision,
        string $principal,
    ): ProcessEvent {
        $state = $instance->currentState($store);
        $gate = $instance->definition->gateFor($state);
        if ($gate === null || $gate->getCode() !== $gateId) {
            throw new \RuntimeException(sprintf(
                "HumanGate: instance '%s' is not currently awaiting gate '%s'.",
                $instance->instanceId,
                $gateId,
            ));
        }

        $opened = $this->latestGateOpened($store, $instance->instanceId, $gateId);
        if ($opened === null) {
            throw new \RuntimeException(sprintf(
                "HumanGate: gate '%s' was never opened for instance '%s'.",
                $gateId,
                $instance->instanceId,
            ));
        }

        /** @var list<string> $options */
        $options = $opened->payload['options'] ?? [];
        if (!in_array($decision, $options, true)) {
            throw new \InvalidArgumentException(sprintf(
                "HumanGate: '%s' is not a valid decision for gate '%s' (expected one of: %s).",
                $decision,
                $gateId,
                implode(', ', $options),
            ));
        }

        $requester = (string) ($opened->payload['requester'] ?? '');
        if ($principal === $requester) {
            throw new SelfApprovalException($principal, $gateId);
        }

        $event = new ProcessEvent($instance->instanceId, $decision, ['by' => $principal], $store->nextSeq());
        $store->append($event);

        return $event;
    }

    /**
     * Read-only reconstruction of "what's the current pending decision on `$instance`, if any" —
     * WITHOUT appending a new `GateOpened` event. Finds the latest `GateOpened` event on
     * `$instance`'s own log and checks whether it is still UNRESOLVED (no later event, by `seq`,
     * whose `type` is one of that `GateOpened`'s own `options`), then rebuilds the {@see
     * PendingDecision} around it exactly like {@see self::openFor()} would have, minus the write.
     *
     * This is the read path F3's report flagged as missing: a "list pending approvals" caller
     * must not call {@see self::openFor()} just to inspect an already-open gate (that would
     * append a redundant `GateOpened`) — it should call this instead. {@see
     * \Milpa\ExampleBlog\Orchestrator\ProcessRunner} also uses it to decide whether a gate still
     * needs opening before it calls {@see self::openFor()}.
     *
     * Returns `null` when: no `GateOpened` event exists for `$instance` at all; the latest one has
     * already been resolved; `$instance`'s current state no longer carries a gate matching the
     * latest `GateOpened`'s `gate_id` (state moved on without a matching resolution event — should
     * not happen in practice, but this method is read-only and simply reports "nothing pending"
     * rather than throwing); its context carries no integer `post_id`; or the post it would need
     * to render can no longer be found.
     */
    public function pendingFor(
        EventStore $store,
        ProcessInstance $instance,
        PostStorageInterface $postLookup,
    ): ?PendingDecision {
        $events = $store->replay($instance->instanceId);
        $opens = array_values(array_filter(
            $events,
            static fn (ProcessEvent $event): bool => $event->type === 'GateOpened',
        ));
        if ($opens === []) {
            return null;
        }

        $latest = $opens[array_key_last($opens)];
        /** @var list<string> $options */
        $options = $latest->payload['options'] ?? [];

        foreach ($events as $event) {
            if ($event->seq > $latest->seq && in_array($event->type, $options, true)) {
                // A later event already resolved this GateOpened — nothing pending.
                return null;
            }
        }

        $gateId = (string) ($latest->payload['gate_id'] ?? '');
        $state = $instance->currentState($store);
        $gate = $instance->definition->gateFor($state);
        if ($gate === null || $gate->getCode() !== $gateId) {
            return null;
        }

        $postId = $instance->context($store)['post_id'] ?? null;
        if (!is_int($postId)) {
            return null;
        }

        $post = $postLookup->find($postId);
        if ($post === null) {
            return null;
        }

        $transitions = $instance->definition->transitionsFrom($state);

        return new PendingDecision(
            instanceId: $instance->instanceId,
            gateId: $gateId,
            assignee: $gate->getApproverRole(),
            artifact: new DecisionArtifact($post->title, $post->body, $transitions),
            options: array_column($transitions, 'name'),
        );
    }

    /**
     * The most recent `GateOpened` event for `$gateId` on `$instanceId`, or `null`
     * if that gate was never opened. "Most recent" matters because `PublishPostProcess`'s
     * revise-and-resubmit loop can open the same gate more than once across an
     * instance's lifetime — only the latest opening's `requester`/`options` are live.
     */
    private function latestGateOpened(EventStore $store, string $instanceId, string $gateId): ?ProcessEvent
    {
        $matches = array_values(array_filter(
            $store->replay($instanceId),
            static fn (ProcessEvent $event): bool => $event->type === 'GateOpened'
                && ($event->payload['gate_id'] ?? null) === $gateId,
        ));

        return $matches === [] ? null : $matches[array_key_last($matches)];
    }
}
