<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Orchestrator\Tools;

use Milpa\ExampleBlog\Blog\PostStorageInterface;
use Milpa\ExampleBlog\Orchestrator\Definitions\PublishPostProcess;
use Milpa\ExampleBlog\Orchestrator\EventStore;
use Milpa\ExampleBlog\Orchestrator\HumanGate;
use Milpa\ExampleBlog\Orchestrator\ProcessInstance;
use Milpa\Live\ValueObjects\RenderTarget;
use Milpa\ToolRuntime\Attributes\Param;
use Milpa\ToolRuntime\Attributes\Tool;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolResult;

/**
 * `process_list_pending_approvals` — a pure READ across every process instance recorded in the
 * {@see EventStore}: which ones are currently awaiting a human decision, rendered as a {@see
 * \Milpa\ExampleBlog\Orchestrator\DecisionArtifact} for the calling channel. Uses {@see
 * HumanGate::pendingFor()} (never {@see HumanGate::openFor()}), so listing never appends a
 * redundant `GateOpened` event — see that method's docblock for why that distinction matters.
 *
 * This slice supports exactly one process definition ({@see PublishPostProcess}), so every
 * instance id the store has ever seen is assumed to be a `publish_post` instance.
 */
final class ProcessListPendingApprovalsTool
{
    private ?ToolContext $context = null;

    public function __construct(
        private readonly EventStore $store,
        private readonly HumanGate $gate,
        private readonly PostStorageInterface $postStorage,
    ) {
    }

    /** Captures the calling {@see ToolContext} — used to pick the render target for each artifact. */
    public function setCurrentContext(ToolContext $ctx): void
    {
        $this->context = $ctx;
    }

    /**
     * @return ToolResult with data `{pending: list<array{instance_id: string, gate_id: string, options: list<string>, artifact: string}>}`
     */
    #[Tool('process_list_pending_approvals', 'List process instances currently awaiting a human decision')]
    public function list(
        #[Param('Filter by the gate\'s approver role (e.g. "editor"); omit to list every pending decision', required: false)]
        ?string $assignee = null,
    ): ToolResult {
        // The plan calls for RenderTarget::HTML on a 'web' channel, RenderTarget::TUI everywhere
        // else (cli/mcp/stdio) — ToolContext::stdio() itself reports channel 'mcp', so 'mcp' folds
        // into the TUI branch here, matching a terminal-adjacent MCP client rather than a browser.
        $target = $this->context?->channel === 'web' ? RenderTarget::HTML : RenderTarget::TUI;

        $pending = [];
        foreach ($this->store->allInstanceIds() as $instanceId) {
            $instance = new ProcessInstance($instanceId, PublishPostProcess::build());
            $decision = $this->gate->pendingFor($this->store, $instance, $this->postStorage);
            if ($decision === null) {
                continue;
            }
            if ($assignee !== null && $decision->assignee !== $assignee) {
                continue;
            }

            $pending[] = [
                'instance_id' => $decision->instanceId,
                'gate_id' => $decision->gateId,
                'options' => $decision->options,
                'artifact' => $decision->artifact->render($target)->output,
            ];
        }

        return ToolResult::success(['pending' => $pending]);
    }
}
