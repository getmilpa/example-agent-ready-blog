<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Plugins\BlogPlugin;

use Milpa\Attributes\PluginMetadata;
use Milpa\Events\VerificationGrantedEvent;
use Milpa\ExampleBlog\Blog\PostStorageInterface;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Interfaces\Plugin\PluginInterface;

/**
 * Plugin B — REQUIRES the PostStorage capability and closes the loop: when a
 * human grants the verification, the `verification.granted` event lands here
 * and THIS handler publishes the post. The "result" arrives via event.
 */
#[PluginMetadata(
    version: '0.1.0',
    author: 'Milpa',
    site: 'https://github.com/getmilpa/example-agent-ready-blog',
    name: 'BlogPlugin',
    type: 'Service',
    requires: [PostStorageInterface::class],
)]
final class BlogPlugin implements PluginInterface
{
    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    public function boot(): void
    {
        /** @var MilpaEventDispatcherInterface $dispatcher */
        $dispatcher = $this->container->get(MilpaEventDispatcherInterface::class);
        $dispatcher->subscribe('verification.granted', function (string $event, array $payload): void {
            $subject = $this->subjectFrom($payload);
            if ($subject === null || preg_match('/^post\.publish:(\d+)$/', $subject, $m) !== 1) {
                return;
            }
            /** @var PostStorageInterface $storage */
            $storage = $this->container->get(PostStorageInterface::class);
            $post = $storage->find((int) $m[1]);
            if ($post === null || $post->status === 'published') {
                return;
            }
            $storage->save($post->withStatus('published', date('c')));
            /** @var MilpaEventDispatcherInterface $d */
            $d = $this->container->get(MilpaEventDispatcherInterface::class);
            $d->dispatch('post.published', ['id' => $post->id]);
        });
    }

    public function install(): void
    {
    }

    public function uninstall(): void
    {
    }

    public function enable(): void
    {
    }

    public function disable(): void
    {
    }

    /**
     * `verification.granted` payload has two shapes in the wild: the flat `['subject' =>
     * string]` this plugin's own unit test dispatches as a minimal simulation, and the real
     * shape {@see \Milpa\ToolRuntime\Verification\HumanVerifier::grant()} actually dispatches
     * — `['event' => VerificationGrantedEvent]` — whose subject only reaches this handler via
     * `$event->getRequest()->subject`. milpa/tool-runtime's README documents that
     * `verification.granted` gets dispatched but never documents this payload shape, so both
     * forms are accepted here rather than assuming one.
     *
     * @param array<string, mixed> $payload
     */
    private function subjectFrom(array $payload): ?string
    {
        if (isset($payload['subject']) && \is_string($payload['subject'])) {
            return $payload['subject'];
        }

        $event = $payload['event'] ?? null;

        return $event instanceof VerificationGrantedEvent ? $event->getRequest()->subject : null;
    }
}
