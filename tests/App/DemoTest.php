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
        $file = sys_get_temp_dir() . '/posts-' . uniqid() . '.json';
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
        $this->assertStringContainsString('confirm_token', $out);
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
