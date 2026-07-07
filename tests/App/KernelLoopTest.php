<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Tests\App;

use Milpa\ExampleBlog\App\Kernel;
use Milpa\ExampleBlog\Blog\PostStorageInterface;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ValueObjects\Verification\VerificationRequest;
use PHPUnit\Framework\TestCase;

final class KernelLoopTest extends TestCase
{
    private string $file;
    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/posts-' . uniqid() . '.json';
        $this->kernel = Kernel::boot($this->file);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testBootRegistersTheFourTools(): void
    {
        $names = array_map(static fn (array $t) => $t['name'], $this->kernel->registry()->getTools());
        sort($names);
        $this->assertSame(['create_post', 'human_verify', 'list_posts', 'publish_post'], $names);
    }

    public function testFullLoopGrantPath(): void
    {
        $registry = $this->kernel->registry();
        $ctx = ToolContext::cli();

        $draft = $registry->call('create_post', ['title' => 'Hello Milpa', 'body' => 'The loop, live.'], $ctx);
        $this->assertTrue($draft->success);
        $id = $draft->data['id'];
        $this->assertSame('draft', $draft->data['status']);

        // publish_post es confirm:true → primera llamada devuelve el confirm-token del registry
        $gate = $registry->call('publish_post', ['id' => $id], $ctx);
        $this->assertTrue($gate->success);
        $this->assertArrayHasKey('confirm_token', $gate->data);

        $events = [];
        $this->kernel->dispatcher()->subscribe('verification.*', function (string $e) use (&$events): void {
            $events[] = $e;
        });

        // redimiendo el token corre el tool → seam de verificación → PENDING + verification.requested
        $pending = $registry->call('publish_post', ['id' => $id, 'confirm_token' => $gate->data['confirm_token']], $ctx);
        $this->assertTrue($pending->success);
        $this->assertSame('pending_verification', $pending->data['status']);
        $this->assertSame(['verification.requested'], $events);

        // el humano aprueba → verification.granted → el handler de BlogPlugin publica (el RESULT llega por evento)
        $request = new VerificationRequest(subject: $pending->data['subject'], requestedBy: 'agent:demo', id: $pending->data['request_id']);
        $this->kernel->verifier()->grant($request, 'human:test');
        $this->assertSame(['verification.requested', 'verification.granted'], $events);

        $storage = $this->kernel->container()->get(PostStorageInterface::class);
        $this->assertSame('published', $storage->find($id)->status);
    }

    public function testRejectPathLeavesDraft(): void
    {
        $registry = $this->kernel->registry();
        $ctx = ToolContext::cli();
        $id = $registry->call('create_post', ['title' => 'No', 'body' => 'Nope'], $ctx)->data['id'];
        $gate = $registry->call('publish_post', ['id' => $id], $ctx);
        $pending = $registry->call('publish_post', ['id' => $id, 'confirm_token' => $gate->data['confirm_token']], $ctx);

        $request = new VerificationRequest(subject: $pending->data['subject'], requestedBy: 'agent:demo', id: $pending->data['request_id']);
        $result = $this->kernel->verifier()->reject($request, 'human:test', 'not good enough');
        $this->assertFalse($result->isSatisfied());

        $storage = $this->kernel->container()->get(PostStorageInterface::class);
        $this->assertSame('draft', $storage->find($id)->status);
    }
}
