<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Tests\App;

use Milpa\ExampleBlog\App\Demo;
use Milpa\ExampleBlog\App\Kernel;
use PHPUnit\Framework\TestCase;

final class DemoTest extends TestCase
{
    private function runDemo(?string $decision, string $stdin = ''): array
    {
        $file = sys_get_temp_dir() . '/posts-' . uniqid() . '.db';
        $in = fopen('php://memory', 'r+');
        fwrite($in, $stdin);
        rewind($in);
        $out = fopen('php://memory', 'r+');
        $code = (new Demo(Kernel::boot($file), $in, $out, $decision))->run();
        rewind($out);
        $output = (string) stream_get_contents($out);
        @unlink($file);

        return [$code, $output];
    }

    public function testAutoApproveRunsTheFullLoop(): void
    {
        [$code, $out] = $this->runDemo('approve');
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Capability graph', $out);
        $this->assertStringContainsString('create_post', $out);
        // Lo que el demo enseña ahora: primero la NEGATIVA en el canal cli, después la firma que
        // nombra la llamada. Antes esto afirmaba 'confirm_token' — el flujo que tool-runtime 0.8
        // dejó de hacer — y la aserción sobrevivió al cambio del demo.
        $this->assertStringContainsString('DENIED', $out);
        $this->assertStringContainsString('signature naming this call', $out);
        // Y el demo no llama «verificada» a una firma que nadie verificó: este bucle la arma a mano
        // y lo dice. La firma de verdad vive en el bucle de procesos (ProcessDemoTest).
        $this->assertStringContainsString('firma DE UTILERÍA', $out);
        $this->assertStringNotContainsString('firma verificada', $out);
        $this->assertStringContainsString('verification.requested', $out);
        $this->assertStringContainsString('verification.granted', $out);
        $this->assertStringContainsString('PUBLISHED', $out);
    }

    public function testRejectPathEndsWithDraft(): void
    {
        [$code, $out] = $this->runDemo('reject');
        $this->assertSame(0, $code);
        $this->assertStringContainsString('verification.rejected', $out);
        $this->assertStringContainsString('still a draft', $out);
    }

    public function testInteractiveReadsDecisionFromStdin(): void
    {
        [, $out] = $this->runDemo(null, "a\n");
        $this->assertStringContainsString('verification.granted', $out);
    }
}
