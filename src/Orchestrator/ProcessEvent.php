<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Orchestrator;

/**
 * Immutable fact appended to the {@see EventStore}'s log. A process instance's state is never
 * stored directly — it is always the fold of its `ProcessEvent`s (see {@see Reducer}), so this VO
 * is the only unit of truth the orchestrator persists.
 *
 * `type` is matched by-name against {@see DefinitionContract::transitionsFrom()} transition names
 * by the reducer: an event whose `type` equals a transition's `name` advances the state; any other
 * `type` (bootstrap/audit facts such as `ProcessStarted` or `StateEntered`) only contributes its
 * `payload` to the folded context and leaves the current state untouched.
 */
final readonly class ProcessEvent
{
    /**
     * @param string              $instanceId the process instance this event belongs to
     * @param string              $type       the event's name; matched against transition names by the reducer
     * @param array<string,mixed> $payload    data carried by this event, folded into the process context
     * @param int                 $seq        monotonic position of this event within its {@see EventStore}
     */
    public function __construct(
        public string $instanceId,
        public string $type,
        public array $payload,
        public int $seq,
    ) {
    }

    /**
     * @return array{instance_id: string, type: string, payload: array<string,mixed>, seq: int}
     */
    public function toArray(): array
    {
        return [
            'instance_id' => $this->instanceId,
            'type' => $this->type,
            'payload' => $this->payload,
            'seq' => $this->seq,
        ];
    }

    /**
     * @param array{instance_id: string, type: string, payload: array<string,mixed>, seq: int} $row
     */
    public static function fromArray(array $row): self
    {
        return new self($row['instance_id'], $row['type'], $row['payload'], $row['seq']);
    }
}
