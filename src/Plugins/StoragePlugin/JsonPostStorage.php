<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Plugins\StoragePlugin;

use Milpa\ExampleBlog\Blog\Post;
use Milpa\ExampleBlog\Blog\PostStorageInterface;

/** Flat-file JSON storage: zero DB, runs anywhere. Not production — that's the point. */
final class JsonPostStorage implements PostStorageInterface
{
    public function __construct(private readonly string $file)
    {
    }

    public function nextId(): int
    {
        $posts = $this->load();

        return $posts === [] ? 1 : max(array_keys($posts)) + 1;
    }

    public function save(Post $post): void
    {
        $posts = $this->load();
        $posts[$post->id] = $post->toArray();
        file_put_contents($this->file, json_encode($posts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function find(int $id): ?Post
    {
        $row = $this->load()[$id] ?? null;

        return $row === null ? null : Post::fromArray($row);
    }

    public function all(): array
    {
        return array_values(array_map(Post::fromArray(...), $this->load()));
    }

    /** @return array<int, array{id: int, title: string, body: string, status: string, created_at: string, published_at: ?string}> */
    private function load(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($this->file), true);

        return \is_array($data) ? $data : [];
    }
}
