<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\App;

use Milpa\Attributes\PluginMetadata;
use Milpa\ValueObjects\Capability\CapabilityProvision;
use Milpa\ValueObjects\Capability\CapabilityRequirement;

/**
 * Hand-rolled provides/requires check over the published capability value
 * objects: reads each plugin's #[PluginMetadata], builds the core VOs, and
 * fails BEFORE boot with a readable message when a requirement has no
 * provider. This is the "A provee / B requiere" edge of the loop.
 */
final class CapabilityGraph
{
    /** @param list<object> $plugins */
    public function check(array $plugins): void
    {
        $provisions = [];
        $requirements = [];
        foreach ($plugins as $plugin) {
            $meta = $this->metadataOf($plugin);
            foreach ($meta->provides as $interface) {
                $provisions[] = new CapabilityProvision(id: $interface, interface: $interface, contractVersion: '1.0.0');
            }
            foreach ($meta->requires as $interface) {
                $requirements[] = [$meta->name, new CapabilityRequirement(id: $interface, interface: $interface)];
            }
        }
        $provided = array_map(static fn (CapabilityProvision $p): string => $p->interface, $provisions);
        foreach ($requirements as [$pluginName, $req]) {
            if (!\in_array($req->interface, $provided, true)) {
                throw new \RuntimeException(
                    "Capability graph unsatisfied: {$pluginName} requires {$req->interface} but no booted plugin provides it."
                );
            }
        }
    }

    private function metadataOf(object $plugin): PluginMetadata
    {
        $attrs = (new \ReflectionClass($plugin))->getAttributes(PluginMetadata::class);
        if ($attrs === []) {
            throw new \RuntimeException($plugin::class . ' has no #[PluginMetadata] attribute.');
        }

        return $attrs[0]->newInstance();
    }
}
