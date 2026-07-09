<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Orchestrator\Tools;

use Milpa\ExampleBlog\Blog\PostStorageInterface;
use Milpa\ExampleBlog\Orchestrator\Definitions\PublishPostProcess;
use Milpa\ExampleBlog\Orchestrator\EventStore;
use Milpa\ExampleBlog\Orchestrator\HumanGate;
use Milpa\ExampleBlog\Orchestrator\ProcessInstance;
use Milpa\ExampleBlog\Orchestrator\ProcessRunner;
use Milpa\ToolRuntime\Attributes\Param;
use Milpa\ToolRuntime\Attributes\Tool;
use Milpa\ToolRuntime\ToolResult;
use Milpa\Workflow\Exceptions\SelfApprovalException;

/**
 * `process_submit_decision` — resolves an open gate via {@see HumanGate::resolve()} then drives
 * the process forward again via {@see ProcessRunner} (so a `grant` reaches `published`, and a
 * `reject` loops back through `draft --submit--> review_gate`, re-opening a fresh gate — both in
 * one call). {@see HumanGate::resolve()}'s three failure modes are each surfaced as a distinct,
 * clean {@see ToolResult::error()} instead of the registry's generic `INTERNAL_ERROR`
 * catch-all — see each `catch` arm below. `SelfApprovalException` MUST be caught before the
 * generic `\RuntimeException` arm since it extends it.
 *
 * Parameters are named `instance_id`/`gate_id` (snake_case), not `instanceId`/`gateId`, on
 * PURPOSE: {@see \Milpa\ToolRuntime\ToolScanner::buildSchema()} takes a tool's wire argument names
 * directly from `ReflectionParameter::getName()` with no case conversion, so the PHP parameter
 * name IS the JSON-RPC argument key a caller must send. The plan's wire contract (and every other
 * snake_case field this slice already uses — `post_id`, `current_state`, `gate_id`) is snake_case,
 * so the PHP parameters are too — confirmed empirically: a camelCase `$instanceId` produced a
 * `Missing required field: instanceId` validation error against a caller sending `instance_id`.
 *
 * A `grant` that reaches `published` ALSO flips the underlying {@see
 * \Milpa\ExampleBlog\Blog\Post}'s own `status` field — see {@see self::publishUnderlyingPost()}.
 * The event-sourced process state and the `Post` entity's `status` are two independent sources of
 * truth (F1-F3 never wired them together; their tests never drove a process to its terminal
 * state), and nothing else in this slice keeps them in sync — confirmed empirically via
 * `bin/process.php --auto-approve`, whose "post is now PUBLISHED" line read the STALE `draft`
 * status before this fix. This mirrors `Milpa\ExampleBlog\Plugins\BlogPlugin\BlogPlugin`'s
 * `verification.granted` handler exactly (`$post->withStatus('published', date('c'))`), just
 * triggered by the process reaching `published` instead of a verification event.
 */
final class ProcessSubmitDecisionTool
{
    public function __construct(
        private readonly EventStore $store,
        private readonly HumanGate $gate,
        private readonly PostStorageInterface $postStorage,
    ) {
    }

    /**
     * @return ToolResult with data `{instance_id: string, current_state: string}` on success
     */
    #[Tool('process_submit_decision', 'Resolve an open gate (e.g. grant or reject) for a process instance')]
    public function submit(
        #[Param('The process instance id', required: true)]
        string $instance_id,
        #[Param('The gate id being resolved (from process_list_pending_approvals)', required: true)]
        string $gate_id,
        #[Param('The decision — must be one of the gate\'s offered options (e.g. "grant"/"reject")', required: true)]
        string $decision,
        #[Param('The resolving principal; must differ from whoever opened the gate', required: true)]
        string $principal,
    ): ToolResult {
        $instance = new ProcessInstance($instance_id, PublishPostProcess::build());

        try {
            $this->gate->resolve($this->store, $instance, $gate_id, $decision, $principal);
        } catch (SelfApprovalException $e) {
            return ToolResult::error('SELF_APPROVAL_FORBIDDEN', $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return ToolResult::error('INVALID_DECISION', $e->getMessage());
        } catch (\RuntimeException $e) {
            return ToolResult::error('GATE_NOT_PENDING', $e->getMessage());
        }

        // Keep recording the ORIGINAL author as the requester of any gate this decision reopens
        // (the revise-and-resubmit loop) — see ProcessInstantiateTool's `_requester` context key.
        $requester = (string) ($instance->context($this->store)['_requester'] ?? $principal);
        (new ProcessRunner())->advance($this->store, $instance, $this->gate, $this->postStorage, $requester);

        $currentState = $instance->currentState($this->store);
        if ($currentState === 'published') {
            $this->publishUnderlyingPost($instance);
        }

        return ToolResult::success([
            'instance_id' => $instance->instanceId,
            'current_state' => $currentState,
        ]);
    }

    /**
     * Flips the `post_id` this instance carries in its context to `status: 'published'` — see the
     * class docblock for why this side effect lives here rather than in the generic {@see
     * ProcessRunner} (which is process-definition-agnostic; this is `publish_post`-specific, and
     * this tool already imports {@see PublishPostProcess} directly). A no-op when the post cannot
     * be found or is already published.
     */
    private function publishUnderlyingPost(ProcessInstance $instance): void
    {
        $postId = $instance->context($this->store)['post_id'] ?? null;
        if (!is_int($postId)) {
            return;
        }

        $post = $this->postStorage->find($postId);
        if ($post === null || $post->status === 'published') {
            return;
        }

        $this->postStorage->save($post->withStatus('published', date('c')));
    }
}
