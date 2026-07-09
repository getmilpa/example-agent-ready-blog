<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Tests\Orchestrator\Fixtures;

use Milpa\ExampleBlog\Blog\Post;
use Milpa\ExampleBlog\Blog\PostStorageInterface;

/** Minimal in-memory {@see PostStorageInterface} stand-in for `HumanGate` tests — no file I/O. */
final class InMemoryPostStorage implements PostStorageInterface
{
    /** @var array<int, Post> */
    private array $posts = [];

    public function nextId(): int
    {
        return $this->posts === [] ? 1 : max(array_keys($this->posts)) + 1;
    }

    public function save(Post $post): void
    {
        $this->posts[$post->id] = $post;
    }

    public function find(int $id): ?Post
    {
        return $this->posts[$id] ?? null;
    }

    /** @return list<Post> */
    public function all(): array
    {
        return array_values($this->posts);
    }
}
