#!/usr/bin/env php
<?php

declare(strict_types=1);

use Milpa\Data\RepositoryInterface;
use Milpa\ExampleBlog\App\Kernel;
use Milpa\ExampleBlog\Blog\Post;
use Milpa\ToolRuntime\Contracts\ToolContext;

require __DIR__ . '/../vendor/autoload.php';

// The interactive walkthrough for the PROCESS loop (mirrors bin/blog.php's verification loop):
// instantiate a publish_post process, run it to its gate, render the DecisionArtifact to THIS
// terminal, let a human grant/reject it, advance, print the outcome. --auto-approve / --reject
// make it non-interactive for CI (see bin/process.php --auto-approve / --reject below).
$decision = null;
if (\in_array('--auto-approve', $argv, true)) {
    $decision = 'grant';
} elseif (\in_array('--reject', $argv, true)) {
    $decision = 'reject';
}

$say = static function (string $line): void {
    fwrite(STDOUT, $line . \PHP_EOL);
};

$kernel = Kernel::boot();
$registry = $kernel->registry();
// The instantiating principal: 'cli' (ToolContext::cli()'s hard-coded identity). The human who
// resolves the gate below uses a DIFFERENT principal ('human:you') so the demo never trips the
// anti-self-approval invariant (HumanGate::resolve() throws SelfApprovalException otherwise).
$ctx = ToolContext::cli();

$say('');
$say('milpa · example-agent-ready-blog — the PROCESS loop, live');
$say('process_instantiate → [auto-advance] → review_gate → human decides → process_submit_decision → [auto-advance]');
$say('');

$postTitle = 'Hello Milpa Process';
$draft = $registry->call('create_post', [
    'title' => $postTitle,
    'body' => 'The publish_post process, demonstrated live: draft -> review_gate -> published.',
], $ctx);
if (!$draft->success) {
    $say("✘ create_post failed: {$draft->error}");

    exit(1);
}
$postId = $draft->data['id'];
$say("→ create_post(\"{$postTitle}\") … draft post #{$postId} created");

$start = $registry->call('process_instantiate', [
    'definition' => 'publish_post',
    // milpa/tool-runtime 0.6's #[Param(type: 'object')] takes `inputs` as a real object — a plain
    // associative array on the wire, no JSON-string workaround (the greenhouse's old deviation).
    'inputs' => ['post_id' => $postId],
], $ctx);
if (!$start->success) {
    $say("✘ process_instantiate failed: {$start->data}");

    exit(1);
}
$instanceId = $start->data['instance_id'];
$say("→ process_instantiate(publish_post, {post_id: {$postId}}) … instance {$instanceId} at {$start->data['current_state']}");

if ($start->data['current_state'] !== 'review_gate') {
    $say("✘ expected the process to auto-advance to review_gate, landed on {$start->data['current_state']} instead.");

    exit(1);
}

$pendingList = $registry->call('process_list_pending_approvals', [], $ctx);
$pending = null;
foreach ($pendingList->data['pending'] as $row) {
    if ($row['instance_id'] === $instanceId) {
        $pending = $row;
        break;
    }
}
if ($pending === null) {
    $say('✘ no pending decision found for this instance — the process did not reach review_gate.');

    exit(1);
}

$say('');
$say('The decision artifact — the package tool returns its mounted {component, data}; this demo');
$say('renders that structured snapshot for the terminal (rendering is the consumer\'s half):');
$say('---');
$artifactData = $pending['artifact']['data'];
$say((string) $artifactData['title']);
$say('');
$say((string) $artifactData['excerpt']);
$say('');
/** @var array<string, string> $labels */
$labels = $artifactData['labels'];
foreach ($labels as $label => $transition) {
    $say(sprintf('[%s] %s (%s)', strtoupper($label), $label, $transition));
}
$say('---');
$say('');

if ($decision === null) {
    fwrite(STDOUT, '? An editor is reviewing this post — [g]rant / [r]eject: ');
    $answer = strtolower(trim((string) fgets(\STDIN)));
    $decision = \in_array($answer, ['g', 'grant'], true) ? 'grant' : 'reject';
}

$submit = $registry->call('process_submit_decision', [
    'instance_id' => $instanceId,
    'gate_id' => $pending['gate_id'],
    'decision' => $decision,
    'principal' => 'human:you',
], $ctx);
if (!$submit->success) {
    $say("✘ process_submit_decision failed: {$submit->data}");

    exit(1);
}

$finalState = $submit->data['current_state'];
if ($finalState === 'published') {
    /** @var RepositoryInterface<Post> $storage */
    $storage = $kernel->container()->get(RepositoryInterface::class);
    $post = $storage->find($postId);
    $status = $post !== null ? strtoupper($post->status) : 'UNKNOWN';
    $say("✔ GRANT — instance {$instanceId} reached PUBLISHED. Post #{$postId} \"{$postTitle}\" is now {$status}.");
} else {
    $say("✘ REJECT — instance {$instanceId} is back at {$finalState} (a fresh gate is open for revision). The loop respected the reviewer's call.");
}
$say('');

exit(0);
