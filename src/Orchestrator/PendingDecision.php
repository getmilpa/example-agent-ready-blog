<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Orchestrator;

/**
 * A gate that {@see HumanGate::openFor()} has opened and is now awaiting a human
 * decision on. `$options` is the gate's transition names (equal by construction to
 * `$artifact`'s own {@see DecisionArtifact::options()}) — carried here too so a
 * caller (e.g. an MCP tool listing pending approvals) does not need to reach into
 * the artifact just to know what decisions are valid.
 */
final readonly class PendingDecision
{
    /**
     * @param string           $instanceId the process instance this decision belongs to
     * @param string           $gateId     the opened gate's code (e.g. `review_gate_gate`)
     * @param string           $assignee   the role expected to resolve this gate (the gate's approver role)
     * @param DecisionArtifact $artifact   the decision surface built for this gate
     * @param list<string>     $options    the transition names available to resolve this gate with
     */
    public function __construct(
        public string $instanceId,
        public string $gateId,
        public string $assignee,
        public DecisionArtifact $artifact,
        public array $options,
    ) {
    }
}
