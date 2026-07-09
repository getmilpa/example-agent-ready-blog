<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Plugins\AgentToolsPlugin;

use Milpa\Data\RepositoryInterface;
use Milpa\ExampleBlog\Blog\Post;
use Milpa\ToolRuntime\Attributes\Param;
use Milpa\ToolRuntime\Attributes\Tool;
use Milpa\ToolRuntime\ToolResult;
use Milpa\ToolRuntime\Verification\HumanVerifier;
use Milpa\ValueObjects\Verification\VerificationContext;
use Milpa\ValueObjects\Verification\VerificationRequest;

/** The agent-callable surface of the blog: three #[Tool] methods. */
final class BlogTools
{
    /**
     * @param RepositoryInterface<Post> $storage
     */
    public function __construct(
        private readonly RepositoryInterface $storage,
        private readonly HumanVerifier $verifier,
    ) {
    }

    #[Tool('create_post', 'Create a draft post')]
    public function createPost(
        #[Param('Post title', required: true)]
        string $title,
        #[Param('Post body', required: true)]
        string $body,
    ): ToolResult {
        // RepositoryInterface::nextId() is `int|string` (a backend could mint non-int ids); Post's id
        // is strictly int, so narrow it here. save() now returns the id used, but the post already
        // carries its own, so the return is not needed.
        $post = new Post((int) $this->storage->nextId(), $title, $body, 'draft', date('c'), null);
        $this->storage->save($post);

        return ToolResult::success(['id' => $post->id, 'status' => 'draft']);
    }

    #[Tool('list_posts', 'List all posts with their status')]
    public function listPosts(): ToolResult
    {
        return ToolResult::success([
            'posts' => array_map(static fn (Post $p): array => $p->toArray(), $this->storage->all()),
        ]);
    }

    #[Tool('publish_post', 'Publish a draft post (requires human verification)', confirm: true)]
    public function publishPost(#[Param('Post id', required: true)] int $id): ToolResult
    {
        $post = $this->storage->find($id);
        if ($post === null) {
            return ToolResult::error('POST_NOT_FOUND', "No post with id {$id}.");
        }
        if ($post->status === 'published') {
            return ToolResult::success(['status' => 'published', 'already' => true]);
        }

        // VerificationRequest's plain constructor leaves `id` null unless one is passed
        // (see vendor/milpa/core/src/ValueObjects/Verification/VerificationRequest.php) — it
        // does NOT autogenerate one. The correlation id (#7) only exists via the
        // `withGeneratedId()` factory, which mints one through the same UuidGenerator trait
        // core's other value objects use. Without this, `$request->id` would be null and the
        // grant/reject round trip below would have nothing to correlate against.
        $request = VerificationRequest::withGeneratedId(subject: "post.publish:{$id}", requestedBy: 'agent:demo');
        $this->verifier->verify($request, new VerificationContext(principal: 'agent:demo'));

        return ToolResult::success([
            'status' => 'pending_verification',
            'subject' => $request->subject,
            'request_id' => $request->id,
        ]);
    }
}
