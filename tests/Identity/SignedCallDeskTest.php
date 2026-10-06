<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Tests\Identity;

use Milpa\Data\RepositoryInterface;
use Milpa\EventStore\FileEventStore;
use Milpa\ExampleBlog\App\Kernel;
use Milpa\ExampleBlog\Identity\ApproverKeyring;
use Milpa\ExampleBlog\Identity\SignedCallDesk;
use Milpa\ExampleBlog\Tests\Support\Sandbox;
use Milpa\ToolRuntime\Attributes\Tool;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Identity\FileNonceLedger;
use Milpa\ToolRuntime\Identity\OperationAuthorization;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ToolRuntime\ToolResult;
use Milpa\ToolRuntime\ToolScanner;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The desk is where a person becomes somebody in this house, so this is where the example's whole
 * claim is tested: an agent proposes, and only an enrolled human's signature over exactly that
 * decision disposes.
 *
 * Every refusal below is a way the signature could have looked acceptable and was not. Each one is
 * the framework's rule ({@see \Milpa\ToolRuntime\Identity\OperationAuthorizer}); what this suite
 * proves is that the example actually goes through it, with real keys and real signatures.
 */
final class SignedCallDeskTest extends TestCase
{
    private Sandbox $sandbox;

    private Kernel $kernel;

    private string $instanceId;

    private string $gateId;

    private int $postId;

    protected function setUp(): void
    {
        $this->sandbox = Sandbox::create();
        $this->kernel = Kernel::boot($this->sandbox->storage(), $this->sandbox->events(), $this->sandbox->identity());

        // The agent's half, the same in every test: a draft, and a process parked at its gate.
        $agent = ToolContext::cli();
        $registry = $this->kernel->registry();
        $this->postId = $registry->call('create_post', ['title' => 'Signed', 'body' => 'A decision worth a signature.'], $agent)->data['id'];
        $started = $registry->call('process_instantiate', [
            'definition' => 'publish_post',
            'inputs' => ['post_id' => $this->postId],
        ], $agent);
        $this->instanceId = $started->data['instance_id'];
        $this->gateId = $registry->call('process_list_pending_approvals', [], $agent)->data['pending'][0]['gate_id'];
    }

    protected function tearDown(): void
    {
        $this->sandbox->destroy();
    }

    /** @return array{instance_id: string, gate_id: string, decision: string} */
    private function decision(string $decision): array
    {
        return ['instance_id' => $this->instanceId, 'gate_id' => $this->gateId, 'decision' => $decision];
    }

    private function desk(): SignedCallDesk
    {
        return $this->kernel->desk();
    }

    private function postStatus(): string
    {
        /** @var RepositoryInterface<\Milpa\ExampleBlog\Blog\Post> $posts */
        $posts = $this->kernel->container()->get(RepositoryInterface::class);

        return (string) $posts->find($this->postId)?->status;
    }

    private function pendingCount(): int
    {
        return \count($this->kernel->registry()->call('process_list_pending_approvals', [], ToolContext::cli())->data['pending']);
    }

    public function testAnEnrolledEditorsSignedDecisionPublishesAndIsRecordedUnderTheirFingerprint(): void
    {
        $editor = $this->sandbox->editor();

        $outcome = Sandbox::signedCall($this->desk(), $editor, 'process_submit_decision', $this->decision('grant'));

        $this->assertTrue($outcome->verdict->granted, (string) $outcome->verdict->reason);
        $this->assertSame($editor->fingerprint(), $outcome->verdict->signer?->fingerprint);
        $this->assertNotNull($outcome->result);
        $this->assertTrue($outcome->result->success);
        $this->assertSame('published', $outcome->result->data['current_state']);
        $this->assertSame('published', $this->postStatus());

        // The log is the record a third party can re-check: the gate was opened by a transport's
        // name, and answered by a key's fingerprint. Two different kinds of thing, on purpose.
        $events = (new FileEventStore($this->sandbox->events()))->replay($this->instanceId);
        $byType = [];
        foreach ($events as $event) {
            $byType[$event->type] = $event->payload;
        }
        $this->assertSame('local-shell', $byType['GateOpened']['requester']);
        $this->assertStringStartsWith((string) $editor->fingerprint(), $byType['grant']['by']);
    }

    public function testAValidSignatureFromAKeyThisHouseNeverEnrolledIsRefused(): void
    {
        $this->sandbox->editor();
        // Somebody with a perfectly good key of their own — an agent that generated one, say.
        $stranger = $this->sandbox->person('stranger');

        // Even when the keyring of whoever runs the process DOES know that key: the house reads its
        // own keyring, not the ambient one.
        $before = getenv('GNUPGHOME');
        putenv('GNUPGHOME=' . $stranger->keyring);

        try {
            $outcome = Sandbox::signedCall($this->desk(), $stranger, 'process_submit_decision', $this->decision('grant'));
            $ambientAfter = getenv('GNUPGHOME');
        } finally {
            putenv($before === false ? 'GNUPGHOME' : 'GNUPGHOME=' . $before);
        }

        $this->assertFalse($outcome->verdict->granted);
        $this->assertNull($outcome->result, 'a refused signature must never reach the tool');
        $this->assertStringContainsString('does not verify, or its key is unknown', (string) $outcome->verdict->reason);
        $this->assertSame($stranger->keyring, $ambientAfter, 'the caller\'s own GNUPGHOME is put back as it was');
        $this->assertSame('draft', $this->postStatus());
        $this->assertSame(1, $this->pendingCount());
    }

    public function testASignatureOverOneDecisionCannotBePresentedForAnother(): void
    {
        $editor = $this->sandbox->editor();

        // The editor signed REJECT...
        $signed = $this->desk()->authorizationFor('process_submit_decision', $this->decision('reject'))->canonical();
        $signature = $editor->sign($signed);

        // ...and it is presented as a GRANT.
        $outcome = $this->desk()->call('process_submit_decision', $this->decision('grant'), $signed, $signature);

        $this->assertFalse($outcome->verdict->granted);
        $this->assertNull($outcome->result);
        $this->assertStringContainsString('different call', (string) $outcome->verdict->reason);
        $this->assertSame('draft', $this->postStatus());
    }

    public function testASignedDecisionWorksOnce(): void
    {
        $editor = $this->sandbox->editor();
        $arguments = $this->decision('reject');
        $signed = $this->desk()->authorizationFor('process_submit_decision', $arguments)->canonical();
        $signature = $editor->sign($signed);

        $first = $this->desk()->call('process_submit_decision', $arguments, $signed, $signature);
        $this->assertTrue($first->verdict->granted, (string) $first->verdict->reason);
        // A reject loops back and re-opens the SAME gate — so the very same bytes would name a live
        // gate again. Only the spent nonce stops them from deciding it a second time.
        $this->assertSame('review_gate', $first->result?->data['current_state']);

        $replayed = $this->desk()->call('process_submit_decision', $arguments, $signed, $signature);

        $this->assertFalse($replayed->verdict->granted);
        $this->assertNull($replayed->result);
        $this->assertStringContainsString('already used', (string) $replayed->verdict->reason);
    }

    public function testAStaleSignatureIsRefused(): void
    {
        $editor = $this->sandbox->editor();
        $arguments = $this->decision('grant');
        $fresh = $this->desk()->authorizationFor('process_submit_decision', $arguments);
        // The same authorization, as if it had been signed ten minutes ago and kept.
        $stale = (new OperationAuthorization($fresh->operation, $fresh->arguments, $fresh->host, gmdate('c', time() - 600), $fresh->nonce))->canonical();

        $outcome = $this->desk()->call('process_submit_decision', $arguments, $stale, $editor->sign($stale));

        $this->assertFalse($outcome->verdict->granted);
        $this->assertNull($outcome->result);
        $this->assertStringContainsString('expired', (string) $outcome->verdict->reason);
        $this->assertSame('draft', $this->postStatus());
    }

    public function testADecisionSignedForAnotherHouseIsRefused(): void
    {
        $editor = $this->sandbox->editor();
        // The same people, a different log: another blog on the same machine that enrolled the same
        // editor. A signature meant for that one is not a signature meant for this one.
        $other = Kernel::boot($this->sandbox->dir . '/other.db', $this->sandbox->dir . '/other.jsonl', $this->sandbox->identity());
        $arguments = $this->decision('grant');
        $signed = $other->desk()->authorizationFor('process_submit_decision', $arguments)->canonical();

        $outcome = $this->desk()->call('process_submit_decision', $arguments, $signed, $editor->sign($signed));

        $this->assertFalse($outcome->verdict->granted);
        $this->assertStringContainsString('different call', (string) $outcome->verdict->reason);
        $this->assertSame('draft', $this->postStatus());
    }

    public function testWithNobodyEnrolledNoSignatureIsRecognised(): void
    {
        $nobodyEnrolledThem = $this->sandbox->person('editor');

        $outcome = Sandbox::signedCall($this->desk(), $nobodyEnrolledThem, 'process_submit_decision', $this->decision('grant'));

        $this->assertFalse($outcome->verdict->granted);
        $this->assertNull($outcome->result);
        $this->assertStringContainsString('nobody is enrolled', (string) $outcome->verdict->reason);
        $this->assertSame('draft', $this->postStatus());
    }

    public function testAnOperationThisHouseDoesNotHaveIsRefused(): void
    {
        $editor = $this->sandbox->editor();

        $outcome = Sandbox::signedCall($this->desk(), $editor, 'drop_everything', ['sure' => true]);

        $this->assertFalse($outcome->verdict->granted);
        $this->assertNull($outcome->result);
        $this->assertStringContainsString("no operation named 'drop_everything'", (string) $outcome->verdict->reason);
    }

    public function testASignedCallCarriesTheOperationsOwnScopesAndNeverTheWildcard(): void
    {
        $editor = $this->sandbox->editor();
        // A house with one scoped tool that reports who it ran as.
        $probe = new class () {
            public ?ToolContext $seen = null;

            public function setCurrentContext(ToolContext $context): void
            {
                $this->seen = $context;
            }

            #[Tool('whoami', 'Reports the caller', scopes: ['blog.decide'])]
            public function whoami(): ToolResult
            {
                return ToolResult::success([]);
            }
        };
        $registry = new ToolRegistry(new NullLogger());
        (new ToolScanner($registry))->scan($probe);
        $desk = new SignedCallDesk(
            $registry,
            new ApproverKeyring($this->sandbox->identity() . '/approvers'),
            new FileNonceLedger($this->sandbox->identity() . '/spent'),
            'scope-test',
        );

        $outcome = Sandbox::signedCall($desk, $editor, 'whoami', []);

        $this->assertTrue($outcome->result?->success, (string) $outcome->verdict->reason);
        $this->assertNotNull($probe->seen);
        // A signature authorizes the operation it names and nothing else: the grant is exactly as
        // wide as that operation's own requirements.
        $this->assertSame(['blog.decide'], $probe->seen->scopes);
        $this->assertStringStartsWith((string) $editor->fingerprint(), (string) $probe->seen->principal);
        $this->assertSame($editor->fingerprint(), $probe->seen->extra['signer.fingerprint']);
    }

    public function testAVerifiedEditorCannotAnswerAGateTheyOpenedThemselves(): void
    {
        $editor = $this->sandbox->editor();

        // The editor — not the agent — starts a process, under their own signature...
        $started = Sandbox::signedCall($this->desk(), $editor, 'process_instantiate', [
            'definition' => 'publish_post',
            'inputs' => ['post_id' => $this->postId],
        ]);
        $this->assertTrue($started->result?->success);
        $theirs = $started->result->data['instance_id'];

        // ...and then signs its approval. The desk admits them: the signature is good. The gate
        // refuses them: being somebody is necessary to decide, and it is not sufficient.
        $outcome = Sandbox::signedCall($this->desk(), $editor, 'process_submit_decision', [
            'instance_id' => $theirs,
            'gate_id' => $this->gateId,
            'decision' => 'grant',
        ]);

        $this->assertTrue($outcome->verdict->granted);
        $this->assertFalse($outcome->result?->success);
        $this->assertSame('SELF_APPROVAL_FORBIDDEN', $outcome->result->error);

        // A second enrolled person can answer it.
        $colleague = $this->sandbox->editor('colleague');
        $second = Sandbox::signedCall($this->desk(), $colleague, 'process_submit_decision', [
            'instance_id' => $theirs,
            'gate_id' => $this->gateId,
            'decision' => 'grant',
        ]);
        $this->assertTrue($second->result?->success);
        $this->assertSame('published', $second->result->data['current_state']);
    }
}
