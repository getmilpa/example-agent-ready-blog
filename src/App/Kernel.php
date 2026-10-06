<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\App;

use Milpa\Container\DIContainer;
use Milpa\Eventing\EventDispatcher;
use Milpa\ExampleBlog\Identity\ApproverKeyring;
use Milpa\ExampleBlog\Identity\SignedCallDesk;
use Milpa\ExampleBlog\Plugins\AgentToolsPlugin\AgentToolsPlugin;
use Milpa\ExampleBlog\Plugins\BlogPlugin\BlogPlugin;
use Milpa\ExampleBlog\Plugins\StoragePlugin\StoragePlugin;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Runtime\Kernel as RuntimeKernel;
use Milpa\ToolRuntime\Identity\FileNonceLedger;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ToolRuntime\Verification\HumanVerifier;
use Milpa\ToolRuntime\Verification\VerificationTool;
use Psr\Log\NullLogger;

/**
 * Thin host bootstrap over {@see RuntimeKernel}. The container, event dispatcher, capability-graph
 * check, dependency-ordered plugin boot and tool-provider auto-registration are ALL delegated to
 * `milpa/runtime` — this class only wires the two collaborators the runtime deliberately does not
 * own (they belong to `milpa/tool-runtime`, the host's opt-in): the {@see HumanVerifier} and the
 * {@see ToolRegistry} it seeds with the `request_verification`/`resolve_verification` tools before
 * handing the registry to the runtime as `$config['toolRegistry']`.
 *
 * And one collaborator that is this application's own: the {@see SignedCallDesk}, the only place a
 * verified identity is made. Every other context in this example names a transport (`local-shell`,
 * `stdio`); a person exists here only as a signature the desk accepted.
 *
 * This is the dogfood: the ~440 lines of inline Container/EventDispatcher/CapabilityGraph/Router
 * seams the example used to carry are gone — `milpa/runtime` supplies the faithful equivalents.
 */
final class Kernel
{
    private function __construct(
        private readonly RuntimeKernel $runtime,
        private readonly ToolRegistry $registry,
        private readonly HumanVerifier $verifier,
        private readonly SignedCallDesk $desk,
    ) {
    }

    public static function boot(?string $storageFile = null, ?string $eventsFile = null, ?string $identityDir = null): self
    {
        $root = \dirname(__DIR__, 2);
        // THE BACKEND IS THIS ONE CONFIG LINE. `driver` picks the milpa/data backend —
        // 'file' (one JSON file), 'sqlite' (a real database in one file), 'mysql' or 'memory' —
        // and StoragePlugin hands the block to RepositoryFactory::fromConfig() untouched, so
        // flipping the driver here re-homes every post with ZERO plugin/tool/test code changes.
        // The path stays overridable per-call (tests, bin/mcp-server.php argv) because the
        // config-driven plugin registry cannot pass constructor args — it travels through
        // milpa/runtime's app-config bag (the `config` key below registers a Milpa\Runtime\Config
        // the plugin reads in boot()).
        $storage = [
            'driver' => 'sqlite', // ← was 'file' + var/posts.json until milpa/data 0.2 — that whole migration is this line
            'path' => $storageFile ?? $root . '/var/blog.db',
        ];
        // Same seam, same reason, for the orchestrator's append-only event log (AgentToolsPlugin
        // reads `orchestrator.events_path` when it wires the 3 process tools) — defaults to
        // var/events.jsonl under the host root, per the plan's zero-DB event store.
        $eventsFile ??= $root . '/var/events.jsonl';
        // What the house knows about people: the public keys it accepts a decision from, and the
        // signed calls it has already honoured once. Public halves only — see ApproverKeyring.
        $identityDir ??= $root . '/var/identity';

        $container = new DIContainer();
        $dispatcher = new EventDispatcher(new NullLogger());

        $verifier = new HumanVerifier($dispatcher);
        $container->registerService(HumanVerifier::class, $verifier);

        $registry = new ToolRegistry(new NullLogger());
        (new VerificationTool($verifier))->register($registry);

        $runtime = RuntimeKernel::boot([
            'root' => $root,
            'container' => $container,
            'dispatcher' => $dispatcher,
            'toolRegistry' => $registry,
            'config' => [
                'storage' => $storage,
                'orchestrator' => ['events_path' => $eventsFile],
            ],
            'plugins' => [
                StoragePlugin::class,
                BlogPlugin::class,
                AgentToolsPlugin::class,
            ],
        ]);

        // The name a signature must carry to be meant for THIS house: the machine and the log the
        // decision lands in. A call signed for one blog is not a call signed for the one next to it.
        $desk = new SignedCallDesk(
            $registry,
            new ApproverKeyring($identityDir . '/approvers'),
            new FileNonceLedger($identityDir . '/spent'),
            gethostname() . ':' . $eventsFile,
        );

        return new self($runtime, $registry, $verifier, $desk);
    }

    public function container(): DIContainerInterface
    {
        return $this->runtime->container();
    }

    public function dispatcher(): MilpaEventDispatcherInterface
    {
        return $this->runtime->dispatcher();
    }

    public function registry(): ToolRegistry
    {
        return $this->registry;
    }

    public function verifier(): HumanVerifier
    {
        return $this->verifier;
    }

    public function desk(): SignedCallDesk
    {
        return $this->desk;
    }

    /** @return list<object> */
    public function plugins(): array
    {
        return $this->runtime->plugins();
    }
}
