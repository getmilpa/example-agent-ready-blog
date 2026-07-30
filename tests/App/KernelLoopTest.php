<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Tests\App;

use Milpa\Data\RepositoryInterface;
use Milpa\ExampleBlog\App\Kernel;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Identity\VerifiedSigner;
use Milpa\ValueObjects\Verification\VerificationRequest;
use PHPUnit\Framework\TestCase;

final class KernelLoopTest extends TestCase
{
    private string $file;
    private Kernel $kernel;

    protected function setUp(): void
    {
        // .db because Kernel::boot()'s shipped storage driver is sqlite — the path is the only
        // override; the backend itself is the one config line in Kernel::boot().
        $this->file = sys_get_temp_dir() . '/posts-' . uniqid() . '.db';
        $this->kernel = Kernel::boot($this->file);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    /**
     * Un contexto autorizado por una firma verificada — la única forma de consentir en el canal cli
     * desde tool-runtime 0.8.
     *
     * El firmante se construye a mano porque lo que se prueba aquí es la FORMA del consentimiento,
     * no la criptografía: el gate mira `signer.fingerprint`, que sólo puede estar ahí porque una
     * firma verificó, y `ToolContext::authorizedBy()` es la única fábrica que lo escribe.
     */
    private function conFirma(): ToolContext
    {
        return ToolContext::authorizedBy(
            new VerifiedSigner(
                fingerprint: '9A2C41F0E7B38D5641AA0C2E7D5FB9C3A18E4402',
                uid: 'test@milpa.lat',
            ),
            ['blog.publish'],
        );
    }

    public function testBootRegistersTheEightTools(): void
    {
        $names = array_map(static fn (array $t) => $t['name'], $this->kernel->registry()->getToolSummaries());
        sort($names);
        $this->assertSame([
            'create_post',
            'list_posts',
            'process_instantiate',
            'process_list_pending_approvals',
            'process_submit_decision',
            'publish_post',
            'request_verification',
            'resolve_verification',
        ], $names);
    }

    public function testFullLoopGrantPath(): void
    {
        $registry = $this->kernel->registry();
        $ctx = ToolContext::cli();

        $draft = $registry->call('create_post', ['title' => 'Hello Milpa', 'body' => 'The loop, live.'], $ctx);
        $this->assertTrue($draft->success);
        $id = $draft->data['id'];
        $this->assertSame('draft', $draft->data['status']);

        // publish_post pide consentimiento explícito, y en el canal cli el consentimiento ES una
        // firma que nombra esta llamada: un `--yes` consiente en abstracto y el mismo sí valdría
        // para cualquier post. Sin firma la llamada se NIEGA — no entrega un token que redimir.
        $negado = $registry->call('publish_post', ['id' => $id], $ctx);
        $this->assertFalse($negado->success, 'sin firma la publicación no procede');
        $this->assertStringContainsString('signature', (string) $negado->error);

        $events = [];
        $this->kernel->dispatcher()->subscribe('verification.*', function (string $e) use (&$events): void {
            $events[] = $e;
        });

        // Con la firma presentada el tool corre → seam de verificación → PENDING + verification.requested
        $pending = $registry->call('publish_post', ['id' => $id], $this->conFirma());
        $this->assertTrue($pending->success);
        $this->assertSame('pending_verification', $pending->data['status']);
        $this->assertSame(['verification.requested'], $events);

        // el humano aprueba → verification.granted → el handler de BlogPlugin publica (el RESULT llega por evento)
        $request = new VerificationRequest(subject: $pending->data['subject'], requestedBy: 'agent:demo', id: $pending->data['request_id']);
        $this->kernel->verifier()->grant($request, 'human:test');
        $this->assertSame(['verification.requested', 'verification.granted'], $events);

        $storage = $this->kernel->container()->get(RepositoryInterface::class);
        $this->assertSame('published', $storage->find($id)->status);
    }

    public function testRejectPathLeavesDraft(): void
    {
        $registry = $this->kernel->registry();
        $ctx = ToolContext::cli();
        $id = $registry->call('create_post', ['title' => 'No', 'body' => 'Nope'], $ctx)->data['id'];

        // Esta prueba pasaba por la razón equivocada: sin firma la primera llamada se niega, así que
        // nada llegaba al seam de verificación y «sigue en draft» se cumplía porque no había pasado
        // NADA. Ahora el post llega de verdad a pending_verification antes de rechazarse.
        $pending = $registry->call('publish_post', ['id' => $id], $this->conFirma());
        $this->assertTrue($pending->success);
        $this->assertSame('pending_verification', $pending->data['status']);

        $request = new VerificationRequest(subject: $pending->data['subject'], requestedBy: 'agent:demo', id: $pending->data['request_id']);
        $result = $this->kernel->verifier()->reject($request, 'human:test', 'not good enough');
        $this->assertFalse($result->isSatisfied());

        $storage = $this->kernel->container()->get(RepositoryInterface::class);
        $this->assertSame('draft', $storage->find($id)->status);
    }
}
