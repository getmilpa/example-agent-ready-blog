<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Orchestrator;

use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;
use Milpa\Orchestrator\DecisionSurfaceInterface;

/**
 * The blog's concrete decision surface for a gated `publish_post` state: a `milpa/live` component
 * ({@see \Milpa\Live\Contracts\Component\ComponentDefinitionInterface}, via {@see
 * DecisionSurfaceInterface}) that renders the post under review plus one action per gate
 * transition. The human label offered for each transition is fixed by {@see self::LABELS}
 * (`approve` -> `grant`, `reject` -> `reject`) — `publish_post`'s single gate shape
 * (`review_gate`'s grant/reject), so the mapping is a class constant rather than a caller-supplied
 * table.
 *
 * This is DOMAIN code the example owns: `milpa/orchestrator` ships only the {@see
 * DecisionSurfaceInterface} contract and the options<->transitions 1:1 invariant (enforced once, in
 * {@see \Milpa\Orchestrator\PendingDecision}'s constructor, for every surface the engine wraps).
 * What a `publish_post` gate LOOKS like — a blog post's title + excerpt — lives here, built by
 * {@see PostDecisionArtifactFactory}.
 */
final readonly class PostDecisionArtifact implements DecisionSurfaceInterface
{
    /** Human label => transition name. The only mapping `publish_post`'s single gate shape needs. */
    private const array LABELS = [
        'approve' => 'grant',
        'reject' => 'reject',
    ];

    private const int EXCERPT_LENGTH = 160;

    /**
     * @param string $postTitle the post's title, as shown to the reviewer
     * @param string $postBody  the post's full body; only an excerpt is rendered
     */
    public function __construct(
        private string $postTitle,
        private string $postBody,
    ) {
    }

    /**
     * The transition names this surface offers, in {@see self::LABELS}' declared order (`grant`
     * then `reject`). {@see \Milpa\Orchestrator\PendingDecision} verifies these equal the gate's
     * transition names 1:1 (order-insensitive), so a `publish_post` definition change that
     * renames/adds/removes a `review_gate` transition desyncs this surface loudly instead of
     * silently offering stale actions.
     *
     * @return list<string>
     */
    public function options(): array
    {
        return array_values(self::LABELS);
    }

    /**
     * The human label => transition name mapping this surface renders as actions.
     *
     * @return array<string, string>
     */
    public function labels(): array
    {
        return self::LABELS;
    }

    /**
     * This surface's runtime contract: the post fields it mounts from, and its single `decide`
     * action (payload: the chosen transition name).
     */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'decision-artifact',
            contractVersion: '0.1.0',
            summary: 'Renders a post under human review with one action per gate transition.',
            propsSchema: [
                'title' => ['type' => 'string', 'required' => true],
                'body' => ['type' => 'string', 'required' => true],
            ],
            stateSchema: [
                'title' => ['type' => 'string'],
                'excerpt' => ['type' => 'string'],
                'options' => ['type' => 'array'],
                'labels' => ['type' => 'array'],
                'decision' => ['type' => 'string|null'],
            ],
            actions: [
                'decide' => ['payload' => ['decision' => 'string']],
            ],
        );
    }

    /**
     * Builds the surface's initial state: the post's title and excerpt, plus the offered
     * options/labels. `$props` is unused — this component's real "props" are its constructor
     * arguments, already fixed when {@see PostDecisionArtifactFactory} built it.
     *
     * @param array<string, mixed> $props
     */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        $contract = self::contract();

        return new StateSnapshot(
            componentId: $context->componentId,
            componentName: $contract->name,
            version: $contract->contractVersion,
            data: [
                'title' => $this->postTitle,
                'excerpt' => $this->excerpt(),
                'options' => $this->options(),
                'labels' => $this->labels(),
                'decision' => null,
            ],
            meta: [
                'principal' => $context->principal,
            ],
        );
    }

    /**
     * Handles the `decide` action: records the chosen transition name in state if it is one of
     * {@see self::options()}, otherwise reports an error and leaves state unchanged. This surface
     * does not itself advance the process — that is {@see \Milpa\Orchestrator\HumanGate::resolve()}'s
     * job once a decision reaches it.
     */
    public function handle(InteractionRequest $request): InteractionResult
    {
        if ($request->action !== 'decide') {
            return new InteractionResult(
                state: $request->state,
                errors: ['action' => "PostDecisionArtifact does not handle action: {$request->action}"],
            );
        }

        $decision = (string) ($request->payload['decision'] ?? '');
        if (!in_array($decision, $this->options(), true)) {
            return new InteractionResult(
                state: $request->state,
                errors: ['decision' => sprintf(
                    "'%s' is not one of this surface's options (%s).",
                    $decision,
                    implode(', ', $this->options()),
                )],
            );
        }

        return new InteractionResult(
            state: new StateSnapshot(
                componentId: $request->state->componentId,
                componentName: $request->state->componentName,
                version: $request->state->version,
                data: array_merge($request->state->data, ['decision' => $decision]),
                meta: $request->state->meta,
            ),
        );
    }

    private function excerpt(): string
    {
        if (mb_strlen($this->postBody) <= self::EXCERPT_LENGTH) {
            return $this->postBody;
        }

        return mb_substr($this->postBody, 0, self::EXCERPT_LENGTH) . '…';
    }
}
