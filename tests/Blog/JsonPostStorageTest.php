<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Tests\Blog;

use Milpa\ExampleBlog\Blog\Post;
use Milpa\ExampleBlog\Plugins\StoragePlugin\JsonPostStorage;
use PHPUnit\Framework\TestCase;

final class JsonPostStorageTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/posts-' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testRoundTripAndIds(): void
    {
        $s = new JsonPostStorage($this->file);
        $this->assertSame(1, $s->nextId());
        $post = new Post(1, 'Hello', 'Body', 'draft', '2026-07-07T00:00:00+00:00', null);
        $s->save($post);
        $this->assertSame(2, $s->nextId());
        $found = $s->find(1);
        $this->assertNotNull($found);
        $this->assertSame('Hello', $found->title);
        $this->assertCount(1, $s->all());
        $this->assertNull($s->find(99));
    }

    public function testPublishTransition(): void
    {
        $s = new JsonPostStorage($this->file);
        $s->save(new Post(1, 'T', 'B', 'draft', '2026-07-07T00:00:00+00:00', null));
        $s->save($s->find(1)->withStatus('published', '2026-07-07T01:00:00+00:00'));
        $this->assertSame('published', $s->find(1)->status);
        $this->assertNotNull($s->find(1)->publishedAt);
    }
}
