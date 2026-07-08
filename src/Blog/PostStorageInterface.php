<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Blog;

/**
 * The capability StoragePlugin PROVIDES and BlogPlugin REQUIRES — the "A
 * provee / B requiere" edge of the Milpa loop, checked by milpa/runtime's
 * capability-graph gate (core's CapabilityGraphChecker) before any plugin boots.
 */
interface PostStorageInterface
{
    public function nextId(): int;

    public function save(Post $post): void;

    public function find(int $id): ?Post;

    /** @return list<Post> */
    public function all(): array;
}
