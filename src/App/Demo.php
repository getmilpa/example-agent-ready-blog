<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\App;

use Milpa\Data\RepositoryInterface;
use Milpa\ExampleBlog\Blog\Post;
use Milpa\ToolRuntime\Contracts\ToolContext;
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

        $gate = $registry->call('publish_post', ['id' => $id], $ctx);
        $token = $gate->data['confirm_token'];
        $this->say("→ publish_post(#{$id}) … INTERCEPTED by the registry confirm gate → confirm_token " . substr($token, 0, 8) . '…');

        $pending = $registry->call('publish_post', ['id' => $id, 'confirm_token' => $token], $ctx);
        $this->say("→ token redeemed … the tool ran and asked the VERIFICATION seam (status: {$pending->data['status']})");

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
