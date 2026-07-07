<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Tests\App;

use Milpa\ExampleBlog\App\CapabilityGraph;
use Milpa\ExampleBlog\App\Container;
use Milpa\ExampleBlog\Blog\PostStorageInterface;
use Milpa\ExampleBlog\Plugins\BlogPlugin\BlogPlugin;
use Milpa\ExampleBlog\Plugins\StoragePlugin\StoragePlugin;
use PHPUnit\Framework\TestCase;

final class CapabilityGraphTest extends TestCase
{
    public function testSatisfiedGraphPasses(): void
    {
        $c = new Container();
        $plugins = [new StoragePlugin($c), new BlogPlugin($c)];
        (new CapabilityGraph())->check($plugins); // no exception
        $this->addToAssertionCount(1);
    }

    public function testMissingProviderFailsBeforeBootWithReadableMessage(): void
    {
        $c = new Container();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(PostStorageInterface::class);
        (new CapabilityGraph())->check([new BlogPlugin($c)]); // falta StoragePlugin
    }
}
