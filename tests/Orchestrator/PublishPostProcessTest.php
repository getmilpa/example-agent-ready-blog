<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Tests\Orchestrator;

use Milpa\ExampleBlog\Orchestrator\Definitions\PublishPostProcess;
use PHPUnit\Framework\TestCase;

/**
 * The DOMAIN definition test: proves {@see PublishPostProcess::build()} composes the concrete
 * `draft -> review_gate[human] -> published` machine out of `milpa/orchestrator`'s generic {@see
 * \Milpa\Orchestrator\ProcessDefinition} + `milpa/workflow`'s state/transition/gate entities. The
 * engine's OWN generic behaviour (acyclic-modulo-gates validation, the reducer, ...) is covered by
 * `packages/milpa-orchestrator`'s suite — this only asserts publish_post's shape.
 */
final class PublishPostProcessTest extends TestCase
{
    public function testTheDefinitionHasTheThreeExpectedStates(): void
    {
        $states = PublishPostProcess::build()->states();
        sort($states);

        $this->assertSame(['draft', 'published', 'review_gate'], $states);
    }

    public function testDraftIsTheInitialState(): void
    {
        $this->assertSame('draft', PublishPostProcess::build()->initialState());
    }

    public function testReviewGateTransitionsAreExactlyGrantAndReject(): void
    {
        $transitions = PublishPostProcess::build()->transitionsFrom('review_gate');
        usort($transitions, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        $this->assertSame(
            [
                ['name' => 'grant', 'to' => 'published'],
                ['name' => 'reject', 'to' => 'draft'],
            ],
            $transitions,
        );
    }

    public function testDraftTransitionsAreExactlySubmit(): void
    {
        $this->assertSame(
            [['name' => 'submit', 'to' => 'review_gate']],
            PublishPostProcess::build()->transitionsFrom('draft'),
        );
    }

    public function testPublishedIsTerminalAndHasNoOutgoingTransitions(): void
    {
        $definition = PublishPostProcess::build();

        $this->assertTrue($definition->isTerminal('published'));
        $this->assertFalse($definition->isTerminal('draft'));
        $this->assertFalse($definition->isTerminal('review_gate'));
        $this->assertSame([], $definition->transitionsFrom('published'));
    }

    public function testReviewGateCarriesAGateDefinitionSharedByBothTransitions(): void
    {
        $definition = PublishPostProcess::build();

        $gate = $definition->gateFor('review_gate');

        $this->assertNotNull($gate);
        $this->assertSame('review_gate_gate', $gate->getCode());
        $this->assertSame('single', $gate->getApprovalPolicyValue());
        $this->assertNull($definition->gateFor('draft'), 'draft has no gated transitions');
        $this->assertNull($definition->gateFor('published'), 'published has no outgoing transitions at all');
    }

    public function testTheRejectToDraftToSubmitLoopIsALegitimateCycleThatDoesNotThrow(): void
    {
        // review_gate --reject--> draft --submit--> review_gate is a real cycle in the raw
        // state graph, but it is broken by the human gate on review_gate (someone must decide
        // grant/reject each time round) — PublishPostProcess::build() below must NOT throw
        // despite it (the acyclic-modulo-gates rule is proven generically upstream).
        $definition = PublishPostProcess::build();

        $reject = array_values(array_filter(
            $definition->transitionsFrom('review_gate'),
            static fn (array $t): bool => $t['name'] === 'reject',
        ))[0];

        $this->assertSame('draft', $reject['to']);
    }
}
