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
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolResult;

/**
 * `process_instantiate` — starts a new process instance from a named process definition and
 * auto-advances it (via {@see ProcessRunner}) up to its first human decision point or terminal
 * state. This slice supports exactly one definition: {@see PublishPostProcess::NAME}.
 *
 * `inputs` is accepted as a JSON-ENCODED STRING, not a nested JSON object — a deliberate deviation
 * from the plan's literal `inputs: object` signature. `milpa/tool-runtime`'s {@see
 * \Milpa\ToolRuntime\ToolScanner} infers a tool's JSON Schema purely from the PHP parameter type,
 * and no PHP type maps to schema `type: object`: a PHP `array` parameter maps to schema `type:
 * array`, and {@see \Milpa\ToolRuntime\SchemaValidator} then enforces THAT via `array_is_list()` —
 * an associative payload like `{"post_id": 1}` decodes (via `json_decode($json, true)`) to a PHP
 * array that is NOT a list, so it is rejected by validation on every real call (confirmed
 * empirically against the vendored `SchemaValidator` before choosing this binding — see the F4
 * report's Fricciones). Accepting `inputs` as a JSON string sidesteps the gap entirely (schema
 * `type: string`, which validates correctly) while keeping the argument's NAME and semantic shape
 * identical to the plan's `inputs: object`.
 */
final class ProcessInstantiateTool
{
    private ?ToolContext $context = null;

    public function __construct(
        private readonly EventStore $store,
        private readonly HumanGate $gate,
        private readonly PostStorageInterface $postStorage,
    ) {
    }

    /** Captures the calling {@see ToolContext} — {@see \Milpa\ToolRuntime\ToolScanner} injects it when this method exists. */
    public function setCurrentContext(ToolContext $ctx): void
    {
        $this->context = $ctx;
    }

    /**
     * @return ToolResult with data `{instance_id: string, current_state: string}` on success
     */
    #[Tool('process_instantiate', 'Start a process instance from a named process definition and run it to its first gate or terminal state')]
    public function instantiate(
        #[Param('Process definition name; only "publish_post" is supported this slice', required: true)]
        string $definition,
        #[Param('JSON-encoded object of definition-specific starting inputs, e.g. \'{"post_id": 1}\'', required: true)]
        string $inputs,
    ): ToolResult {
        if ($definition !== PublishPostProcess::NAME) {
            return ToolResult::error(
                'UNKNOWN_DEFINITION',
                "No process definition named '{$definition}'. Supported: " . PublishPostProcess::NAME . '.',
            );
        }

        $decoded = json_decode($inputs, true);
        if (!is_array($decoded)) {
            return ToolResult::error('INVALID_INPUTS', 'inputs must be a JSON-encoded object.');
        }

        // Not `$this->context?->principal ?? 'unknown'`: PHPStan flags that nullsafe access as
        // `nullsafe.neverNull` (level 6, confirmed against an isolated repro) even though
        // `$this->context` genuinely defaults to `null` until `setCurrentContext()` runs — the
        // explicit null check below is both PHPStan-clean and avoids a PHP "read property on
        // null" warning if this tool is ever invoked without going through the scanner's context
        // injection.
        $requester = $this->context !== null ? ($this->context->principal ?? 'unknown') : 'unknown';
        // Carried in the process's context so a LATER re-open of the same gate (the
        // revise-and-resubmit loop after a reject) keeps recording the ORIGINAL author as the
        // requester — see ProcessSubmitDecisionTool.
        $decoded['_requester'] = $requester;

        $processDefinition = PublishPostProcess::build();
        $instance = ProcessInstance::start($this->store, $processDefinition, $decoded);

        (new ProcessRunner())->advance($this->store, $instance, $this->gate, $this->postStorage, $requester);

        return ToolResult::success([
            'instance_id' => $instance->instanceId,
            'current_state' => $instance->currentState($this->store),
        ]);
    }
}
