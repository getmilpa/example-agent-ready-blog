<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Orchestrator;

use Milpa\Support\UuidGenerator;

/**
 * A process instance handle: an opaque `instanceId` bound to the {@see ProcessDefinition} that
 * governs it. Holds NO event data of its own — every state-reading method takes the {@see
 * EventStore} explicitly and replays through the {@see Reducer}, so two `ProcessInstance`
 * objects built from the same `instanceId` + `definition` against the same log always agree, and
 * neither one ever caches a stale answer ("state is a projection", not a stored field).
 *
 * The constructor is the "attach to an existing instance" path — cheap, side-effect-free,
 * usable any time a caller already knows an `instanceId` (e.g. an MCP tool call carrying one).
 * {@see self::start()} is the ONLY path that creates a brand-new instance (it is the one that
 * writes to the store); calling it twice with the same `instanceId` would duplicate the
 * bootstrap events, so callers resuming an existing instance must use `new self(...)` instead.
 */
final readonly class ProcessInstance
{
    use UuidGenerator;

    public function __construct(
        public string $instanceId,
        public ProcessDefinition $definition,
    ) {
    }

    /**
     * Starts a brand-new process instance: appends `ProcessStarted` (payload = `$inputs`), then
     * `StateEntered` (payload = `{state: $definition->initialState()}`), to `$store`, and
     * returns the resulting handle. `$instanceId` defaults to a freshly generated UUID (via
     * {@see UuidGenerator}, the same mechanism `VerificationRequest::withGeneratedId()` uses
     * elsewhere in this example) when omitted — pass one explicitly for deterministic tests or
     * caller-assigned ids.
     *
     * @param array<string, mixed> $inputs the new instance's starting context (e.g. `{post_id: 1}`)
     */
    public static function start(
        EventStore $store,
        ProcessDefinition $definition,
        array $inputs,
        ?string $instanceId = null,
    ): self {
        $instanceId ??= self::generateUuid();

        $store->append(new ProcessEvent($instanceId, 'ProcessStarted', $inputs, $store->nextSeq()));
        $store->append(new ProcessEvent($instanceId, 'StateEntered', ['state' => $definition->initialState()], $store->nextSeq()));

        return new self($instanceId, $definition);
    }

    /**
     * The full projection — current state and accumulated context — replayed fresh from
     * `$store` on every call.
     */
    public function state(EventStore $store): ProcessState
    {
        return (new Reducer())->apply($store->replay($this->instanceId), $this->definition);
    }

    /**
     * Convenience accessor for {@see self::state()}'s `currentState`.
     */
    public function currentState(EventStore $store): string
    {
        return $this->state($store)->currentState;
    }

    /**
     * Convenience accessor for {@see self::state()}'s `context`.
     *
     * @return array<string, mixed>
     */
    public function context(EventStore $store): array
    {
        return $this->state($store)->context;
    }
}
