<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Tests\App;

use Milpa\ExampleBlog\App\Container;
use Milpa\Exceptions\ContainerResolutionException;
use Milpa\Exceptions\ServiceNotFoundException;
use Milpa\Interfaces\Di\DIContainerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class ContainerTest extends TestCase
{
    public function testImplementsThePublishedContract(): void
    {
        $c = new Container();
        $this->assertInstanceOf(DIContainerInterface::class, $c);
        $this->assertInstanceOf(ContainerInterface::class, $c);
        $this->assertSame($c, $c->getContainer());
    }

    public function testRegisterInstanceAndGet(): void
    {
        $c = new Container();
        $service = new \stdClass();
        $c->registerService('demo', $service);
        $this->assertTrue($c->has('demo'));
        $this->assertSame($service, $c->get('demo'));
        $this->assertSame($service, $c->tryGet('demo'));
    }

    public function testRegisterClassStringIsLazilyInstantiatedOnceAsSingleton(): void
    {
        $c = new Container();
        $c->registerService(\ArrayObject::class, \ArrayObject::class);
        $first = $c->get(\ArrayObject::class);
        $this->assertInstanceOf(\ArrayObject::class, $first);
        $this->assertSame($first, $c->get(\ArrayObject::class));
    }

    public function testGetUnknownThrowsTypedNotFound(): void
    {
        $this->expectException(ServiceNotFoundException::class);
        (new Container())->get('nope');
    }

    public function testTryGetUnknownReturnsNull(): void
    {
        $this->assertNull((new Container())->tryGet('nope'));
    }

    public function testResolveClassWithRequiredCtorParamsThrowsResolutionException(): void
    {
        $this->expectException(ContainerResolutionException::class);
        (new Container())->resolve(\ReflectionMethod::class); // requiere args de ctor
    }

    public function testGetAutoResolvesUnregisteredClassAndItsConstructorDependencyChain(): void
    {
        $c = new Container();
        $root = $c->get(ContainerTestRoot::class);

        $this->assertInstanceOf(ContainerTestRoot::class, $root);
        $this->assertInstanceOf(ContainerTestBranch::class, $root->branch);
        $this->assertInstanceOf(ContainerTestLeaf::class, $root->branch->leaf);

        // Each level of the auto-resolved chain is registered as a singleton too.
        $this->assertSame($root->branch, $c->get(ContainerTestBranch::class));
        $this->assertSame($root->branch->leaf, $c->get(ContainerTestLeaf::class));
        $this->assertSame($root, $c->get(ContainerTestRoot::class));
    }

    public function testHasReturnsTrueForAnExistingUnregisteredClass(): void
    {
        $c = new Container();
        $this->assertTrue($c->has(ContainerTestLeaf::class));
        $this->assertFalse($c->has('TotallyMadeUpClassThatDoesNotExist'));
    }

    public function testTryGetNeverThrowsAndReturnsNullOnBadFactoryRegistration(): void
    {
        $c = new Container();
        // Registered as a factory, but its constructor requires a scalar with
        // no default — resolution fails internally. tryGet() must swallow it.
        $c->registerService('bad', ContainerTestRequiresScalar::class);

        $this->assertNull($c->tryGet('bad'));
    }

    public function testResolveOnAbstractClassThrowsResolutionException(): void
    {
        $this->expectException(ContainerResolutionException::class);
        (new Container())->resolve(ContainerTestAbstract::class);
    }

    public function testResolveOnInterfaceThrowsResolutionException(): void
    {
        $this->expectException(ContainerResolutionException::class);
        (new Container())->resolve(ContainerTestInterface::class);
    }
}

abstract class ContainerTestAbstract
{
}

interface ContainerTestInterface
{
}

final class ContainerTestLeaf
{
}

final class ContainerTestBranch
{
    public function __construct(public ContainerTestLeaf $leaf)
    {
    }
}

final class ContainerTestRoot
{
    public function __construct(public ContainerTestBranch $branch)
    {
    }
}

final class ContainerTestRequiresScalar
{
    public function __construct(public string $requiredScalar)
    {
    }
}
