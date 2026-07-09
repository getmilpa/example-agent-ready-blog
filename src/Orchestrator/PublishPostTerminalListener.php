<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Orchestrator;

use Milpa\Data\RepositoryInterface;
use Milpa\ExampleBlog\Blog\Post;
use Milpa\ExampleBlog\Orchestrator\Definitions\PublishPostProcess;

/**
 * Closes the `publish_post` loop's terminal seam: when a process instance reaches its terminal
 * state, `milpa/orchestrator`'s {@see \Milpa\Orchestrator\ProcessRunner} dispatches a
 * `process.terminal` event (payload `{instance_id, final_state, context}`) — this listener catches
 * it and flips the underlying {@see \Milpa\ExampleBlog\Blog\Post} to `published`.
 *
 * This is finding #4 of the orchestrator extraction, made concrete: in the greenhouse the post was
 * published INSIDE `ProcessSubmitDecisionTool` (a `publish_post`-specific side effect wired into an
 * otherwise generic tool). The generic tool now touches no domain entity at all — reaching a
 * terminal state is surfaced purely via the `process.terminal` event, and THIS domain listener runs
 * the effect, subscribed on the same `MilpaEventDispatcherInterface` the runner dispatches on
 * (wired at boot by {@see \Milpa\ExampleBlog\Plugins\AgentToolsPlugin\AgentToolsPlugin}). It mirrors
 * {@see \Milpa\ExampleBlog\Plugins\BlogPlugin\BlogPlugin}'s `verification.granted` handler exactly
 * (`$post->withStatus('published', date('c'))`), just triggered by the process reaching its terminal
 * state instead of by a verification event.
 */
final readonly class PublishPostTerminalListener
{
    /**
     * @param RepositoryInterface<Post> $posts
     */
    public function __construct(private RepositoryInterface $posts)
    {
    }

    /**
     * Publishes the post carried by `$payload['context']['post_id']` when a `publish_post` instance
     * reaches its terminal `published` state. A no-op for any other terminal state, a context with
     * no integer `post_id`, or a post that is missing or already published.
     *
     * @param array<string, mixed> $payload the `process.terminal` payload — `{instance_id, final_state, context}`
     */
    public function onProcessTerminal(string $event, array $payload): void
    {
        if (($payload['final_state'] ?? null) !== PublishPostProcess::STATE_PUBLISHED) {
            return;
        }

        $context = $payload['context'] ?? [];
        $postId = is_array($context) ? ($context['post_id'] ?? null) : null;
        if (!is_int($postId)) {
            return;
        }

        $post = $this->posts->find($postId);
        if ($post === null || $post->status === 'published') {
            return;
        }

        $this->posts->save($post->withStatus('published', date('c')));
    }
}
