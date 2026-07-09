<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Orchestrator;

use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\RenderResult;
use Milpa\Live\ValueObjects\RenderTarget;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * The human decision surface for a gated process state: a `milpa/live` component
 * ({@see ComponentDefinitionInterface}) that renders the post under review plus one
 * action per gate transition. The human label offered for each transition is fixed
 * by {@see self::LABELS} (`approve` -> `grant`, `reject` -> `reject`) — this slice has
 * exactly one gate shape (`review_gate`'s grant/reject), so the mapping is a class
 * constant rather than a caller-supplied table.
 *
 * The artifact<->gate 1:1 invariant lives in the constructor: the `$transitions` a
 * caller passes in (normally {@see ProcessDefinition::transitionsFrom()}'s output for
 * the gated state) MUST carry exactly the transition names {@see self::LABELS} maps
 * to, or construction throws — a process definition change that renames/adds/removes
 * a `review_gate` transition desyncs the artifact loudly instead of silently offering
 * stale actions.
 *
 * `ComponentDefinitionInterface` itself owns no rendering (that is a paired
 * {@see \Milpa\Live\Contracts\Rendering\ComponentRendererInterface}'s job in the real
 * API) — see {@see self::render()}'s docblock for why this artifact grows its own
 * `supportsTarget()`/`render()` pair instead of a separate renderer class.
 */
final readonly class DecisionArtifact implements ComponentDefinitionInterface
{
    /** Human label => transition name. The only mapping this slice's single gate shape needs. */
    private const array LABELS = [
        'approve' => 'grant',
        'reject' => 'reject',
    ];

    private const int EXCERPT_LENGTH = 160;

    /**
     * @param string                                $postTitle   the post's title, as shown to the reviewer
     * @param string                                $postBody    the post's full body; only an excerpt is rendered
     * @param list<array{name: string, to: string}> $transitions the gate's outgoing transitions (typically
     *                                                           {@see ProcessDefinition::transitionsFrom()}'s
     *                                                           output for the gated state) — MUST carry exactly
     *                                                           {@see self::LABELS}'s transition names
     *
     * @throws \InvalidArgumentException when `$transitions`' names do not exactly match {@see self::LABELS}'s
     *                                   transition names (the artifact<->gate 1:1 invariant)
     */
    public function __construct(
        private string $postTitle,
        private string $postBody,
        private array $transitions,
    ) {
        $expected = array_values(self::LABELS);
        sort($expected);

        $actual = array_column($this->transitions, 'name');
        sort($actual);

        if ($expected !== $actual) {
            throw new \InvalidArgumentException(sprintf(
                "DecisionArtifact: gate transitions [%s] do not match this artifact's offered options [%s].",
                implode(', ', $actual),
                implode(', ', $expected),
            ));
        }
    }

    /**
     * The transition names this artifact offers, in {@see self::LABELS}' declared order
     * (`grant` then `reject`) — equal by construction to the gate's transition names.
     *
     * @return list<string>
     */
    public function options(): array
    {
        return array_values(self::LABELS);
    }

    /**
     * The human label => transition name mapping this artifact renders as actions.
     *
     * @return array<string, string>
     */
    public function labels(): array
    {
        return self::LABELS;
    }

    /**
     * This artifact's runtime contract: the post fields it mounts from, and its single
     * `decide` action (payload: the chosen transition name).
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
     * Builds the artifact's initial state: the post's title and excerpt, plus the
     * offered options/labels. `$props` is unused — this component's real "props" are
     * its constructor arguments, already fixed when it was built.
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
     * Handles the `decide` action: records the chosen transition name in state if it
     * is one of {@see self::options()}, otherwise reports an error and leaves state
     * unchanged. This artifact does not itself advance the process — that is
     * {@see HumanGate::resolve()}'s job once a decision reaches it.
     */
    public function handle(InteractionRequest $request): InteractionResult
    {
        if ($request->action !== 'decide') {
            return new InteractionResult(
                state: $request->state,
                errors: ['action' => "DecisionArtifact does not handle action: {$request->action}"],
            );
        }

        $decision = (string) ($request->payload['decision'] ?? '');
        if (!in_array($decision, $this->options(), true)) {
            return new InteractionResult(
                state: $request->state,
                errors: ['decision' => sprintf(
                    "'%s' is not one of this artifact's options (%s).",
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

    /**
     * Whether this artifact can render itself for `$target`: web (HTML) and TUI, not
     * ANSI (no ANSI renderer exists anywhere in the `milpa/live` lab yet — see
     * {@see RenderTarget}'s own docblock).
     */
    public function supportsTarget(RenderTarget $target): bool
    {
        return $target === RenderTarget::HTML || $target === RenderTarget::TUI;
    }

    /**
     * Renders this artifact to a string for `$target`.
     *
     * The real `milpa/live` API deliberately keeps rendering OUT of
     * {@see ComponentDefinitionInterface}: turning a mounted component into
     * markup/TUI text is a separate {@see \Milpa\Live\Contracts\Rendering\ComponentRendererInterface}'s
     * job, decoupled so several renderers can share one component definition. That
     * decoupling buys nothing for a single always-self-paired decision surface like
     * this one, so rather than writing a one-to-one `DecisionArtifactRenderer` class
     * whose `render(ComponentDefinitionInterface $component, ...)` signature would
     * always be called with `$this`, this artifact grows its own `supportsTarget()`/
     * `render()` pair alongside the real `mount()`/`handle()` contract. See the F3
     * report's Fricciones for the full gap writeup.
     *
     * @throws \InvalidArgumentException when `$target` is not {@see self::supportsTarget()}
     */
    public function render(RenderTarget $target, ?ComponentContext $context = null): RenderResult
    {
        if (!$this->supportsTarget($target)) {
            throw new \InvalidArgumentException(sprintf(
                "DecisionArtifact does not support render target '%s'.",
                $target->value,
            ));
        }

        $context ??= new ComponentContext(componentId: 'decision-artifact');
        $state = $this->mount([], $context);

        $output = $target === RenderTarget::TUI ? $this->renderTui() : $this->renderHtml();

        return new RenderResult(output: $output, state: $state, format: $target);
    }

    private function renderTui(): string
    {
        $lines = [$this->postTitle, '', $this->excerpt(), ''];

        foreach (self::LABELS as $label => $transition) {
            $lines[] = sprintf('[%s] %s (%s)', strtoupper($label), $label, $transition);
        }

        return implode("\n", $lines);
    }

    private function renderHtml(): string
    {
        $buttons = '';
        foreach (self::LABELS as $label => $transition) {
            $buttons .= sprintf(
                '<button data-transition="%s">%s</button>',
                htmlspecialchars($transition, ENT_QUOTES),
                htmlspecialchars($label, ENT_QUOTES),
            );
        }

        return sprintf(
            '<article><h1>%s</h1><p>%s</p><div class="actions">%s</div></article>',
            htmlspecialchars($this->postTitle, ENT_QUOTES),
            htmlspecialchars($this->excerpt(), ENT_QUOTES),
            $buttons,
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
