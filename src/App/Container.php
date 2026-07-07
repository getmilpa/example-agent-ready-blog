<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\App;

use Milpa\Exceptions\ContainerResolutionException;
use Milpa\Exceptions\ServiceNotFoundException;
use Milpa\Interfaces\Di\DIContainerInterface;
use Psr\Container\ContainerInterface;

/**
 * Minimal DIContainerInterface implementation: explicit registration plus
 * honest constructor autowiring, exactly as the published contract promises
 * — no more. get() auto-resolves unregistered-but-existing classes and their
 * constructor dependency chain; tryGet() never throws and never auto-resolves.
 */
final class Container implements DIContainerInterface
{
    /** @var array<string, object> */
    private array $instances = [];

    /** @var array<string, class-string> */
    private array $factories = [];

    public function getContainer(): ContainerInterface
    {
        return $this;
    }

    public function registerService(string $id, string|object $classOrInstance): void
    {
        if (\is_object($classOrInstance)) {
            $this->instances[$id] = $classOrInstance;

            return;
        }
        $this->factories[$id] = $classOrInstance;
    }

    /** No-op: this container has nothing to compile. Present to satisfy the contract. */
    public function compileContainer(): void
    {
    }

    public function get(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        if (isset($this->factories[$id])) {
            return $this->instances[$id] = $this->resolve($this->factories[$id]);
        }
        if (!class_exists($id)) {
            throw ServiceNotFoundException::forId($id);
        }

        // Not registered, but the class exists: auto-resolve and register as
        // a singleton, per the DIContainerInterface::get() docblock.
        return $this->resolve($id);
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]) || class_exists($id);
    }

    public function resolve(string $className, bool $singleton = true): mixed
    {
        if ($singleton && isset($this->instances[$className])) {
            return $this->instances[$className];
        }

        try {
            $reflection = new \ReflectionClass($className);
        } catch (\ReflectionException $e) {
            throw new ContainerResolutionException("Cannot resolve unknown class: {$className}", 0, $e);
        }
        if (!$reflection->isInstantiable()) {
            throw new ContainerResolutionException(
                "Cannot resolve {$className}: it is an interface or abstract class — register a concrete instance instead."
            );
        }

        $ctor = $reflection->getConstructor();
        $args = [];
        if ($ctor !== null) {
            foreach ($ctor->getParameters() as $parameter) {
                $args[] = $this->resolveConstructorParameter($className, $parameter);
            }
        }

        $instance = $reflection->newInstanceArgs($args);
        if ($singleton) {
            $this->instances[$className] = $instance;
        }

        return $instance;
    }

    public function tryGet(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        if (isset($this->factories[$id])) {
            try {
                return $this->instances[$id] = $this->resolve($this->factories[$id]);
            } catch (\Throwable) {
                // tryGet() never throws and never auto-resolves — a bad factory
                // registration is reported as "not available", not an error.
                return null;
            }
        }

        // No auto-resolution here: per the docblock, only get() does that.
        return null;
    }

    /**
     * Minimal autowiring: a required class/interface-typed parameter is
     * fetched recursively via {@see self::get()}; anything else falls back
     * to its default value, then null if allowed, then a clear failure.
     */
    private function resolveConstructorParameter(string $forClass, \ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();

        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
            return $this->get($type->getName());
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($parameter->allowsNull()) {
            return null;
        }

        throw new ContainerResolutionException(
            "Cannot autowire {$forClass}: parameter \${$parameter->getName()} has no class/interface "
            . 'type-hint and no default value — register it explicitly.'
        );
    }
}
