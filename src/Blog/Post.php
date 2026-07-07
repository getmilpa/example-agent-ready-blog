<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Blog;

/** Immutable blog post. `status` is 'draft' or 'published'. */
final readonly class Post
{
    public function __construct(
        public int $id,
        public string $title,
        public string $body,
        public string $status,
        public string $createdAt,
        public ?string $publishedAt,
    ) {
    }

    public function withStatus(string $status, ?string $publishedAt = null): self
    {
        return new self($this->id, $this->title, $this->body, $status, $this->createdAt, $publishedAt);
    }

    /** @return array{id: int, title: string, body: string, status: string, created_at: string, published_at: ?string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'status' => $this->status,
            'created_at' => $this->createdAt,
            'published_at' => $this->publishedAt,
        ];
    }

    /** @param array{id: int, title: string, body: string, status: string, created_at: string, published_at: ?string} $row */
    public static function fromArray(array $row): self
    {
        return new self($row['id'], $row['title'], $row['body'], $row['status'], $row['created_at'], $row['published_at']);
    }
}
