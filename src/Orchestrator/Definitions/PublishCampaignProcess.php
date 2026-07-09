<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Orchestrator\Definitions;

use Milpa\Orchestrator\ProcessDefinition;
use Milpa\Orchestrator\SubprocessSpec;
use Milpa\Workflow\Entities\StateDefinition;
use Milpa\Workflow\Entities\TransitionDefinition;

/**
 * The `publish_campaign` process: a thin PARENT that composes {@see PublishPostProcess} as a
 * SUBPROCESS — the proof that "todo es un proceso, recursivamente componible".
 *
 * `draft --launch--> review (subprocess: publish_post) --[published]--> announced --announce-->
 * done`. The `review` state is a {@see SubprocessSpec}: entering it, `milpa/orchestrator`'s {@see
 * \Milpa\Orchestrator\ProcessRunner} instantiates a FRESH `publish_post` child on its own stream,
 * projecting this campaign's own `post_id` into the child's `post_id` input, drives the child to
 * ITS OWN `review_gate`, and the campaign then WAITS — it does not auto-advance past `review`.
 *
 * The human still resolves ONLY the child's `review_gate` (via the unchanged {@see
 * \Milpa\Orchestrator\HumanGate}/`process_submit_decision`). The instant that decision drives
 * `publish_post` to its terminal `published` state, the runner routes the outcome back here as an
 * event typed `published` (= the child's terminal state code, NOT the literal `subprocess_done` —
 * see {@see \Milpa\Orchestrator\ProcessRunner}'s docblock for why): the SUBPROCESS-DONE transition
 * out of `review` MUST therefore be coded {@see PublishPostProcess::STATE_PUBLISHED}, so the
 * unchanged {@see \Milpa\Orchestrator\Reducer}'s `event.type === transition.name` rule folds it.
 * That advances the campaign to `announced` (a trivial automated state) and on to terminal `done`,
 * all inside the same `process_submit_decision` call — the whole nested composition, event-sourced.
 *
 * Inputs: `{post_id: int}`, the same shape `publish_post` takes — a campaign for a post IS a
 * publish of that post wrapped in an announce step, so the child's inputs are just projected
 * straight through (`inputsMap: {post_id: post_id}`), and `post_id` is declared a subprocess
 * `output` so it is carried back into the campaign's own context when the child finishes.
 */
final class PublishCampaignProcess
{
    /** The domain/lookup key this process's states and transitions are namespaced under. */
    public const string NAME = 'publish_campaign';

    public const string STATE_DRAFT = 'draft';
    public const string STATE_REVIEW = 'review';
    public const string STATE_ANNOUNCED = 'announced';
    public const string STATE_DONE = 'done';

    /** Transition names are the literal event `type`s that advance them (see {@see \Milpa\Orchestrator\Reducer}). */
    public const string TRANSITION_LAUNCH = 'launch';

    public const string TRANSITION_ANNOUNCE = 'announce';

    /**
     * Builds the `publish_campaign` {@see ProcessDefinition}: 4 states, 3 transitions, 1 subprocess
     * state (`review`, delegating to {@see PublishPostProcess}). A fresh definition is built on
     * every call — `milpa/workflow`'s entities are plain, never-persisted objects here, so there is
     * no shared mutable state to guard between callers (mirrors {@see PublishPostProcess::build()}).
     */
    public static function build(): ProcessDefinition
    {
        $draft = (new StateDefinition())
            ->setDomain(self::NAME)
            ->setCode(self::STATE_DRAFT)
            ->setLabel('Draft campaign')
            ->setSortOrder(0)
            ->setIsInitial(true);

        $review = (new StateDefinition())
            ->setDomain(self::NAME)
            ->setCode(self::STATE_REVIEW)
            ->setLabel('Review (publish_post subprocess)')
            ->setSortOrder(1);

        $announced = (new StateDefinition())
            ->setDomain(self::NAME)
            ->setCode(self::STATE_ANNOUNCED)
            ->setLabel('Announced')
            ->setSortOrder(2);

        $done = (new StateDefinition())
            ->setDomain(self::NAME)
            ->setCode(self::STATE_DONE)
            ->setLabel('Done')
            ->setSortOrder(3)
            ->setIsTerminal(true);

        $launch = (new TransitionDefinition())
            ->setDomain(self::NAME)
            ->setCode(self::TRANSITION_LAUNCH)
            ->setLabel('Launch review')
            ->setFromState($draft)
            ->setToState($review);

        // Coded after publish_post's terminal state ('published') — the child's only possible
        // outcome — NOT the literal string 'subprocess_done'. This is what the runner appends to
        // THIS parent's stream when the child finishes, so the unchanged Reducer folds it.
        $subprocessDone = (new TransitionDefinition())
            ->setDomain(self::NAME)
            ->setCode(PublishPostProcess::STATE_PUBLISHED)
            ->setLabel('Post published (subprocess done)')
            ->setFromState($review)
            ->setToState($announced);

        $announce = (new TransitionDefinition())
            ->setDomain(self::NAME)
            ->setCode(self::TRANSITION_ANNOUNCE)
            ->setLabel('Announce the published post')
            ->setFromState($announced)
            ->setToState($done);

        return new ProcessDefinition(
            [$draft, $review, $announced, $done],
            [$launch, $subprocessDone, $announce],
            [
                self::STATE_REVIEW => new SubprocessSpec(
                    definitionRef: PublishPostProcess::NAME,
                    inputsMap: ['post_id' => 'post_id'],
                    outputs: ['post_id'],
                ),
            ],
        );
    }
}
