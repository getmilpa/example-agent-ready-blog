<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Tests\App;

use Milpa\ExampleBlog\Tests\Support\Sandbox;
use PHPUnit\Framework\TestCase;

/**
 * Runs the process-loop demo the way the README tells a reader to: the scripts themselves, each a
 * process of its own, against a throwaway house.
 *
 * The demo is a claim — the agent proposes, a person with an identity of their own disposes — and a
 * demo nobody runs drifts from its claim quietly. So the refusals are asserted here too: if the
 * agent's session ever managed to answer its own gate, this is the suite that would go red.
 */
final class ProcessDemoTest extends TestCase
{
    private Sandbox $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = Sandbox::create();
    }

    protected function tearDown(): void
    {
        $this->sandbox->destroy();
    }

    public function testTheAgentsSessionProposesAndIsRefusedAtItsOwnGate(): void
    {
        [$exit, $out, $err] = $this->sandbox->run('process.php');

        $this->assertSame(0, $exit, $out . $err);
        $this->assertStringContainsString("requested by 'local-shell'", $out);
        // Twice: once as itself, once naming somebody else through the retired `principal` argument.
        $this->assertSame(2, substr_count($out, 'REFUSED · UNVERIFIED_APPROVER'));
        $this->assertStringNotContainsString('ACCEPTED', $out);
        $this->assertStringContainsString('is still a DRAFT', $out);
        $this->assertStringContainsString('php bin/decide.php', $out);
    }

    public function testAPersonEnrollsThenDecidesAndThePostIsPublished(): void
    {
        [$exit, $enrolled, $err] = $this->sandbox->run('enroll.php');
        $this->assertSame(0, $exit, $enrolled . $err);
        $this->assertStringContainsString('This is a DEMO identity', $enrolled);
        $this->assertSame(1, preg_match('/✔ ([0-9A-F]{40}) /', $enrolled, $found));
        $fingerprint = $found[1];

        $this->sandbox->run('process.php');

        [$exit, $out, $err] = $this->sandbox->run('decide.php', ['--grant']);
        $this->assertSame(0, $exit, $out . $err);
        // What was signed names the call...
        $this->assertStringContainsString('"operation":"process_submit_decision"', $out);
        $this->assertStringContainsString('"decision":"grant"', $out);
        // ...the house recognised who signed it...
        $this->assertStringContainsString("signed by {$fingerprint}", $out);
        // ...and the log records the requester and the approver as two different kinds of thing.
        $this->assertStringContainsString("requested by 'local-shell'", $out);
        $this->assertStringContainsString("grant — by {$fingerprint}", $out);
        $this->assertStringContainsString('now PUBLISHED', $out);
    }

    public function testAPersonsRejectSendsThePostBackForRevision(): void
    {
        $this->sandbox->run('enroll.php');
        $this->sandbox->run('process.php');

        [$exit, $out, $err] = $this->sandbox->run('decide.php', ['--reject']);

        $this->assertSame(0, $exit, $out . $err);
        $this->assertStringContainsString("is back at 'review_gate'", $out);
        $this->assertStringContainsString('still a DRAFT', $out);
    }

    public function testThePromptTakesThePersonsAnswerAndAnythingButGrantIsAReject(): void
    {
        $this->sandbox->run('enroll.php');
        $this->sandbox->run('process.php');

        // Silence is not consent: an empty answer rejects.
        [, $silent] = $this->sandbox->run('decide.php');
        $this->assertStringContainsString('"decision":"reject"', $silent);
        $this->assertStringContainsString('still a DRAFT', $silent);

        [$exit, $out, $err] = $this->sandbox->run('decide.php', [], "g\n");
        $this->assertSame(0, $exit, $out . $err);
        $this->assertStringContainsString('"decision":"grant"', $out);
        $this->assertStringContainsString('now PUBLISHED', $out);
    }

    public function testGrantAndRejectTogetherDecideNothing(): void
    {
        $this->sandbox->run('enroll.php');
        $this->sandbox->run('process.php');

        [$exit, $out, $err] = $this->sandbox->run('decide.php', ['--grant', '--reject']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Pick one', $err);
        $this->assertSame('', $out, 'nothing was signed and nothing was decided');
    }

    public function testWithSeveralGatesWaitingThePersonSaysWhichOneTheyAreDeciding(): void
    {
        $this->sandbox->run('enroll.php');
        [, $first] = $this->sandbox->run('process.php');
        [, $second] = $this->sandbox->run('process.php');
        $older = self::instanceIn($first);
        $newer = self::instanceIn($second);

        // Named: the older gate is decided and the newer one is left alone.
        [$exit, $out, $err] = $this->sandbox->run('decide.php', ['--grant', '--instance=' . $older]);
        $this->assertSame(0, $exit, $out . $err);
        $this->assertStringContainsString('(+1 more', $out);
        $this->assertStringContainsString("\"instance_id\":\"{$older}\"", $out);
        $this->assertStringContainsString('Post #1 ', $out);

        // Unnamed: the newest instance still waiting — here, the only one left.
        [, $out] = $this->sandbox->run('decide.php', ['--reject']);
        $this->assertStringContainsString("\"instance_id\":\"{$newer}\"", $out);
        $this->assertStringContainsString('Post #2 ', $out);

        [$exit, , $err] = $this->sandbox->run('decide.php', ['--grant', '--instance=not-an-instance']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString("no gate is waiting on instance 'not-an-instance'", $err);
    }

    private static function instanceIn(string $agentOutput): string
    {
        self::assertSame(1, preg_match('/instance ([0-9a-f-]{36}) at review_gate/', $agentOutput, $found));

        return $found[1];
    }

    public function testWithNoIdentityThereIsNobodyToDecide(): void
    {
        $this->sandbox->run('process.php');

        [$exit, $out, $err] = $this->sandbox->run('decide.php', ['--grant']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('php bin/enroll.php', $err);
        $this->assertStringNotContainsString('PUBLISHED', $out);
    }

    public function testAKeyTheHouseNeverEnrolledDecidesNothing(): void
    {
        $this->sandbox->run('process.php');
        // Somebody generated a key — an agent could — but the house was never told to listen to it.
        $this->sandbox->person('editor');

        [$exit, $out, $err] = $this->sandbox->run('decide.php', ['--grant']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('the house did not accept the signature', $err);
        $this->assertStringNotContainsString('PUBLISHED', $out);
    }

    public function testEnrollingAgainKeepsTheSameIdentity(): void
    {
        [, $first] = $this->sandbox->run('enroll.php');
        [$exit, $second] = $this->sandbox->run('enroll.php');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('you already have a demo key', $second);
        preg_match('/✔ ([0-9A-F]{40}) /', $first, $a);
        preg_match('/✔ ([0-9A-F]{40}) /', $second, $b);
        $this->assertSame($a[1], $b[1]);
        $this->assertSame(1, substr_count($second, 'Demo Editor'), 'the house lists one approver, not two');
    }

    public function testTheCampaignsAgentIsRefusedAndAPersonsGrantRunsItToDone(): void
    {
        [$exit, $agent, $err] = $this->sandbox->run('campaign.php');
        $this->assertSame(0, $exit, $agent . $err);
        $this->assertStringContainsString('REFUSED · UNVERIFIED_APPROVER', $agent);
        $this->assertStringNotContainsString('ACCEPTED', $agent);
        $this->assertStringContainsString("keeps WAITING at 'review'", $agent);

        $this->sandbox->run('enroll.php');
        [$exit, $out, $err] = $this->sandbox->run('decide.php', ['--grant']);

        $this->assertSame(0, $exit, $out . $err);
        // One decision on the leaf gate; the outcome routes up and the parent finishes too.
        $this->assertMatchesRegularExpression('/publish_post \w{8}  ProcessTerminalReached — published/', $out);
        $this->assertMatchesRegularExpression('/publish_campaign \w{8}  ProcessTerminalReached — done/', $out);
        $this->assertStringContainsString('now PUBLISHED', $out);
    }

    public function testTheOldDecideFlagsOnTheAgentsScriptDecideNothing(): void
    {
        [$exit, $out, $err] = $this->sandbox->run('process.php', ['--auto-approve']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('no longer decides anything', $err);
        $this->assertStringContainsString('is still a DRAFT', $out);
    }
}
