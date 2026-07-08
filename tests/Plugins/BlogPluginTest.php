<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Tests\Plugins;

use Milpa\Container\DIContainer;
use Milpa\Eventing\EventDispatcher;
use Milpa\ExampleBlog\Blog\Post;
use Milpa\ExampleBlog\Blog\PostStorageInterface;
use Milpa\ExampleBlog\Plugins\BlogPlugin\BlogPlugin;
use Milpa\ExampleBlog\Plugins\StoragePlugin\JsonPostStorage;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class BlogPluginTest extends TestCase
{
    public function testVerificationGrantedPublishesThePost(): void
    {
        $file = sys_get_temp_dir() . '/posts-' . uniqid() . '.json';
        $storage = new JsonPostStorage($file);
        $storage->save(new Post(1, 'T', 'B', 'draft', '2026-07-07T00:00:00+00:00', null));

        $c = new DIContainer();
        $dispatcher = new EventDispatcher(new NullLogger());
        $c->registerService(MilpaEventDispatcherInterface::class, $dispatcher);
        $c->registerService(PostStorageInterface::class, $storage);

        $published = [];
        $dispatcher->subscribe('post.published', function (string $e, array $p) use (&$published): void {
            $published[] = $p['id'];
        });

        (new BlogPlugin($c))->boot();
        $dispatcher->dispatch('verification.granted', ['subject' => 'post.publish:1']);

        $this->assertSame('published', $storage->find(1)->status);
        $this->assertSame([1], $published);
        @unlink($file);
    }

    public function testUnrelatedSubjectIsIgnored(): void
    {
        $file = sys_get_temp_dir() . '/posts-' . uniqid() . '.json';
        $storage = new JsonPostStorage($file);
        $storage->save(new Post(1, 'T', 'B', 'draft', '2026-07-07T00:00:00+00:00', null));

        $c = new DIContainer();
        $dispatcher = new EventDispatcher(new NullLogger());
        $c->registerService(MilpaEventDispatcherInterface::class, $dispatcher);
        $c->registerService(PostStorageInterface::class, $storage);

        (new BlogPlugin($c))->boot();
        $dispatcher->dispatch('verification.granted', ['subject' => 'gate:something.else']);

        $this->assertSame('draft', $storage->find(1)->status);
        @unlink($file);
    }
}
