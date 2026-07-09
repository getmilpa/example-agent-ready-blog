<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Orchestrator\Definitions;

use Milpa\Orchestrator\ProcessDefinition;
use Milpa\Workflow\Entities\GateDefinition;
use Milpa\Workflow\Entities\StateDefinition;
use Milpa\Workflow\Entities\TransitionDefinition;
use Milpa\Workflow\Enums\ApprovalPolicy;

/**
 * The concrete `publish_post` process: a post's publish journey as a 3-state machine.
 *
 * `draft --submit--> review_gate --grant--> published`, with `review_gate --reject--> draft`
 * sending a rejected draft back for revision (resubmitting starts the loop again). `review_gate`
 * is a human checkpoint: both of its outgoing transitions (`grant`, `reject`) carry the SAME
 * {@see GateDefinition} instance — one decision, two possible outcomes — under a single-approval
 * policy. The anti-self-approval invariant itself is NOT a property of this `GateDefinition`; it
 * is enforced by `milpa/workflow`'s `GatePassageService::approvePassage()` at the service layer
 * (a later slice wires that up) — see that class's docblock.
 *
 * Inputs: `{post_id: int}`, carried as the `ProcessStarted` event payload by whoever calls
 * `ProcessInstance::start()` with this definition.
 */
final class PublishPostProcess
{
    /** The domain/lookup key this process's states, transitions and gate are namespaced under. */
    public const string NAME = 'publish_post';

    public const string STATE_DRAFT = 'draft';
    public const string STATE_REVIEW_GATE = 'review_gate';
    public const string STATE_PUBLISHED = 'published';

    /** Transition names are the literal event `type`s that advance them (see {@see \Milpa\Orchestrator\Reducer}). */
    public const string TRANSITION_SUBMIT = 'submit';

    public const string TRANSITION_GRANT = 'grant';
    public const string TRANSITION_REJECT = 'reject';

    /**
     * Builds the `publish_post` {@see ProcessDefinition}: 3 states, 3 transitions, 1 human gate
     * on `review_gate`. A fresh definition is built on every call — `milpa/workflow`'s entities
     * are plain objects here (never persisted), so there is no shared mutable state to guard
     * between callers.
     */
    public static function build(): ProcessDefinition
    {
        $draft = (new StateDefinition())
            ->setDomain(self::NAME)
            ->setCode(self::STATE_DRAFT)
            ->setLabel('Draft')
            ->setSortOrder(0)
            ->setIsInitial(true);

        $reviewGate = (new StateDefinition())
            ->setDomain(self::NAME)
            ->setCode(self::STATE_REVIEW_GATE)
            ->setLabel('Review Gate')
            ->setSortOrder(1);

        $published = (new StateDefinition())
            ->setDomain(self::NAME)
            ->setCode(self::STATE_PUBLISHED)
            ->setLabel('Published')
            ->setSortOrder(2)
            ->setIsTerminal(true);

        $gate = (new GateDefinition())
            ->setDomain(self::NAME)
            ->setCode('review_gate_gate')
            ->setName('Editorial review')
            ->setDescription('A human reviewer grants or rejects the draft; the requester cannot approve their own submission.')
            ->setRequesterRole('author')
            ->setApproverRole('editor')
            ->setApprovalPolicy(ApprovalPolicy::SINGLE);

        $submit = (new TransitionDefinition())
            ->setDomain(self::NAME)
            ->setCode(self::TRANSITION_SUBMIT)
            ->setLabel('Submit for review')
            ->setFromState($draft)
            ->setToState($reviewGate);

        $grant = (new TransitionDefinition())
            ->setDomain(self::NAME)
            ->setCode(self::TRANSITION_GRANT)
            ->setLabel('Grant')
            ->setFromState($reviewGate)
            ->setToState($published);
        $grant->addGateDefinition($gate);

        $reject = (new TransitionDefinition())
            ->setDomain(self::NAME)
            ->setCode(self::TRANSITION_REJECT)
            ->setLabel('Reject')
            ->setFromState($reviewGate)
            ->setToState($draft);
        $reject->addGateDefinition($gate);

        return new ProcessDefinition(
            [$draft, $reviewGate, $published],
            [$submit, $grant, $reject],
        );
    }
}
