<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\App;

use Milpa\Data\RepositoryInterface;
use Milpa\ExampleBlog\Blog\Post;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Identity\VerifiedSigner;
use Milpa\ValueObjects\Verification\VerificationRequest;

/**
 * The interactive walkthrough: narrates every stage of the loop by its real
 * contract/event name, and lets a human grant or reject the publication.
 */
final class Demo
{
    /**
     * @param resource $stdin
     * @param resource $stdout
     */
    public function __construct(
        private readonly Kernel $kernel,
        private $stdin,
        private $stdout,
        private readonly ?string $decision = null,
    ) {
    }

    public function run(): int
    {
        $registry = $this->kernel->registry();
        $ctx = ToolContext::cli();

        $this->say('');
        $this->say('milpa · example-agent-ready-blog — the loop, live');
        $this->say('plugin → capability → tool → verification → event → result');
        $this->say('');
        $this->say('✔ Capability graph: StoragePlugin provides PostStorage → BlogPlugin requires it');
        $names = array_map(static fn (array $t) => $t['name'], $registry->getToolSummaries());
        sort($names);
        $this->say('✔ ' . \count($this->kernel->plugins()) . ' plugins booted · tools: ' . implode(', ', $names));

        // Los eventos del seam se imprimen EN VIVO, con su nombre real.
        $this->kernel->dispatcher()->subscribe('verification.*', function (string $event): void {
            $this->say("  ⚡ {$event}");
        }, 100);
        $this->kernel->dispatcher()->subscribe('post.published', function (string $event, array $p): void {
            $this->say("  ⚡ {$event} (id {$p['id']})");
        }, 100);

        $this->say('');
        $draft = $registry->call('create_post', ['title' => 'Hello Milpa', 'body' => 'The loop, demonstrated live.'], $ctx);
        $id = $draft->data['id'];
        $this->say("→ create_post(\"Hello Milpa\") … draft #{$id} created (not mutating-gated: no friction)");

        // El canal `cli` toma el consentimiento COMO FIRMA desde tool-runtime 0.8, y el runtime lo
        // explica mejor que este comentario: «--yes consiente el borrado en abstracto, así que el
        // mismo sí cubre borrar cualquier plugin en cualquier host. Una firma nombra el objetivo, así
        // que no se puede presentar para otro.»
        //
        // Antes esto era un `confirm_token`: la herramienta devolvía «¿estás seguro?» y se redimía el
        // token. Ese flujo dejó de ser el de CLI, y el demo lo enseña en dos pasos porque los dos
        // importan — primero la negativa, después la firma.
        $negado = $registry->call('publish_post', ['id' => $id], $ctx);
        $this->say("→ publish_post(#{$id}) … DENIED: " . ($negado->error ?? $negado->message));
        $this->say('  el canal cli no acepta un «sí» genérico — pide una firma que nombre ESTA llamada');

        // En un host real la firma se verifica y de ahí sale el VerifiedSigner. Aquí se construye
        // uno para que el demo corra sin llaves: lo que se demuestra es la FORMA del consentimiento,
        // no la criptografía, que vive en `milpa/governance`.
        $firmante = new VerifiedSigner(
            fingerprint: '9A2C41F0E7B38D5641AA0C2E7D5FB9C3A18E4402',
            uid: 'demo@milpa.lat',
        );
        $conFirma = ToolContext::authorizedBy($firmante, ['blog.publish']);
        $this->say('→ se presenta una firma verificada · ' . substr($firmante->fingerprint, 0, 8) . '… (' . $firmante->uid . ')');

        $pending = $registry->call('publish_post', ['id' => $id], $conFirma);
        $this->say("→ autorizado por la firma … la herramienta corrió y preguntó al seam de VERIFICACIÓN (status: {$pending->data['status']})");

        $decision = $this->decision ?? $this->prompt("? An agent wants to publish post #{$id} — [a]pprove / [r]eject: ");
        $request = new VerificationRequest(
            subject: $pending->data['subject'],
            requestedBy: 'agent:demo',
            id: $pending->data['request_id'],
        );

        if (\in_array($decision, ['approve', 'a'], true)) {
            $this->kernel->verifier()->grant($request, 'human:you');
            /** @var RepositoryInterface<Post> $storage */
            $storage = $this->kernel->container()->get(RepositoryInterface::class);
            $status = strtoupper($storage->find($id)->status);
            $this->say("✔ post #{$id} is now {$status} — the result arrived via event, handled by BlogPlugin");
            $this->say('');
            $this->say('See it: php -S localhost:8080 -t public   →   http://localhost:8080');
        } else {
            $this->kernel->verifier()->reject($request, 'human:you', 'rejected from the demo');
            $this->say("✘ rejected — post #{$id} is still a draft. The loop respected your call.");
        }
        $this->say('');

        return 0;
    }

    private function prompt(string $question): string
    {
        $this->write($question);
        $answer = strtolower(trim((string) fgets($this->stdin)));

        return \in_array($answer, ['a', 'approve'], true) ? 'approve' : 'reject';
    }

    private function say(string $line): void
    {
        $this->write($line . PHP_EOL);
    }

    private function write(string $text): void
    {
        fwrite($this->stdout, $text);
    }
}
