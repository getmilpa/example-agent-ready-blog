<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Tests\Orchestrator;

use Milpa\ExampleBlog\Orchestrator\DecisionArtifact;
use Milpa\ExampleBlog\Orchestrator\Definitions\PublishPostProcess;
use Milpa\Live\ValueObjects\RenderTarget;
use PHPUnit\Framework\TestCase;

final class DecisionArtifactTest extends TestCase
{
    public function testItOffersExactlyApproveGrantAndRejectReject(): void
    {
        $transitions = PublishPostProcess::build()->transitionsFrom('review_gate');

        $artifact = new DecisionArtifact('Draft title', 'Draft body.', $transitions);

        $this->assertSame(['approve' => 'grant', 'reject' => 'reject'], $artifact->labels());

        $options = $artifact->options();
        sort($options);
        $this->assertSame(['grant', 'reject'], $options);
    }

    public function testItSupportsWebAndTuiTargetsButNotAnsi(): void
    {
        $transitions = PublishPostProcess::build()->transitionsFrom('review_gate');
        $artifact = new DecisionArtifact('Draft title', 'Draft body.', $transitions);

        $this->assertTrue($artifact->supportsTarget(RenderTarget::HTML));
        $this->assertTrue($artifact->supportsTarget(RenderTarget::TUI));
        $this->assertFalse($artifact->supportsTarget(RenderTarget::ANSI));
    }

    public function testRenderingToHtmlContainsThePostTitleAndBothActionLabels(): void
    {
        $transitions = PublishPostProcess::build()->transitionsFrom('review_gate');
        $artifact = new DecisionArtifact('The post under review', 'Body text.', $transitions);

        $output = $artifact->render(RenderTarget::HTML)->output;

        $this->assertStringContainsString('The post under review', $output);
        $this->assertStringContainsString('approve', $output);
        $this->assertStringContainsString('reject', $output);
    }

    public function testRenderingToTuiContainsThePostTitleAndBothActionLabels(): void
    {
        $transitions = PublishPostProcess::build()->transitionsFrom('review_gate');
        $artifact = new DecisionArtifact('The post under review', 'Body text.', $transitions);

        $output = $artifact->render(RenderTarget::TUI)->output;

        $this->assertStringContainsString('The post under review', $output);
        $this->assertStringContainsString('approve', $output);
        $this->assertStringContainsString('reject', $output);
    }

    public function testRenderingToAnUnsupportedTargetThrows(): void
    {
        $transitions = PublishPostProcess::build()->transitionsFrom('review_gate');
        $artifact = new DecisionArtifact('Title', 'Body.', $transitions);

        $this->expectException(\InvalidArgumentException::class);

        $artifact->render(RenderTarget::ANSI);
    }

    public function testBuildingItAgainstMismatchedTransitionsThrowsAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DecisionArtifact('Title', 'Body.', [
            ['name' => 'grant', 'to' => 'published'],
            // no 'reject' transition — mismatches the artifact's fixed approve/reject options
        ]);
    }

    public function testBuildingItAgainstAnUnrelatedThirdTransitionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DecisionArtifact('Title', 'Body.', [
            ['name' => 'grant', 'to' => 'published'],
            ['name' => 'reject', 'to' => 'draft'],
            ['name' => 'escalate', 'to' => 'review_gate'],
        ]);
    }

    public function testLongBodiesAreExcerptedNotRenderedInFull(): void
    {
        $transitions = PublishPostProcess::build()->transitionsFrom('review_gate');
        $longBody = str_repeat('lorem ipsum dolor sit amet ', 20);
        $artifact = new DecisionArtifact('Title', $longBody, $transitions);

        $output = $artifact->render(RenderTarget::HTML)->output;

        $this->assertStringNotContainsString($longBody, $output);
    }
}
