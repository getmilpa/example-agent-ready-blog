<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Orchestrator;

use Milpa\ExampleBlog\Blog\PostStorageInterface;
use Milpa\Orchestrator\DecisionSurfaceFactoryInterface;
use Milpa\Orchestrator\DecisionSurfaceInterface;
use Milpa\Orchestrator\ProcessInstance;

/**
 * Builds the `publish_post` {@see PostDecisionArtifact} for a process instance sitting at its
 * gated `review_gate` state — the example's implementation of `milpa/orchestrator`'s {@see
 * DecisionSurfaceFactoryInterface}, injected into the package {@see \Milpa\Orchestrator\HumanGate}.
 *
 * This is exactly the `post_id -> PostStorageInterface -> Post` lookup the greenhouse's inline
 * `HumanGate::openFor()`/`pendingFor()` used to do themselves: the generic engine now delegates
 * "what does this gate's decision surface look like?" to this domain factory, so it never needs to
 * know a `publish_post` instance carries a `post_id` that resolves to a blog {@see
 * \Milpa\ExampleBlog\Blog\Post} with a title and a body.
 */
final readonly class PostDecisionArtifactFactory implements DecisionSurfaceFactoryInterface
{
    public function __construct(private PostStorageInterface $posts)
    {
    }

    /**
     * Reads `post_id` out of the instance's `$context`, looks the post up, and builds a {@see
     * PostDecisionArtifact} around its title and body.
     *
     * On the write path ({@see \Milpa\Orchestrator\HumanGate::openFor()}) a throw here propagates —
     * a gate cannot be opened for a post that does not exist. On the read path ({@see
     * \Milpa\Orchestrator\HumanGate::pendingFor()}) the engine catches it and reports "nothing
     * pending" rather than failing the whole listing.
     *
     * @param list<array{name: string, to: string}> $transitions the gate's outgoing transitions (unused here:
     *                                                           the label<->transition mapping is fixed by
     *                                                           {@see PostDecisionArtifact}, and the 1:1 check
     *                                                           against these names runs in {@see
     *                                                           \Milpa\Orchestrator\PendingDecision})
     * @param array<string, mixed>                  $context     the instance's current context — carries `post_id`
     *
     * @throws \RuntimeException when the context has no integer `post_id`, or no post is found for it
     */
    public function build(ProcessInstance $instance, array $transitions, array $context): DecisionSurfaceInterface
    {
        $postId = $context['post_id'] ?? null;
        if (!is_int($postId)) {
            throw new \RuntimeException(sprintf(
                "PostDecisionArtifactFactory: instance '%s' context has no integer 'post_id'.",
                $instance->instanceId,
            ));
        }

        $post = $this->posts->find($postId);
        if ($post === null) {
            throw new \RuntimeException("PostDecisionArtifactFactory: post #{$postId} not found.");
        }

        return new PostDecisionArtifact($post->title, $post->body);
    }
}
