#!/usr/bin/env php
<?php

declare(strict_types=1);

use Milpa\Data\RepositoryInterface;
use Milpa\ExampleBlog\App\Kernel;
use Milpa\ExampleBlog\Blog\Post;
use Milpa\ToolRuntime\Contracts\ToolContext;

require __DIR__ . '/../vendor/autoload.php';

// SESSION 1 OF 2 — THE AGENT PROPOSES.
//
// This terminal is the agent's. It drafts a post, starts the publish_post process, and the process
// parks at its human gate. Then the agent does what an agent that wants its work shipped does next:
// it tries to answer that gate itself. It is refused — first as itself, then again while naming
// somebody else — and the post stays a draft.
//
// The decision is made in ANOTHER session, by a person, under that person's own signature:
// `php bin/decide.php`. This script cannot make it, and has no flag that pretends to.
//
// --storage=<file> and --events=<file> override where the house keeps its posts and its event log;
// the test suite uses them to run this against a throwaway house.
$options = getopt('', ['storage:', 'events:']);
$option = static fn (string $name): ?string => is_string($options[$name] ?? null) ? $options[$name] : null;

$say = static function (string $line): void {
    fwrite(STDOUT, $line . PHP_EOL);
};

if (array_intersect(['--auto-approve', '--reject'], $argv) !== []) {
    fwrite(STDERR, 'note: this script no longer decides anything — the agent proposes here, and a person decides with `php bin/decide.php --grant|--reject`.' . PHP_EOL);
}

$kernel = Kernel::boot($option('storage'), $option('events'));
$registry = $kernel->registry();
// Who the agent is to the house: 'local-shell'. That is the honest name for an unsigned terminal —
// a process that reached the machine, not a person — and it is all an agent session ever has.
$agent = ToolContext::cli();

$say('');
$say('milpa · example-agent-ready-blog — the PROCESS loop · session 1 of 2: the agent proposes');
$say('process_instantiate → [auto-advance] → review_gate ⏸   a person answers it:  php bin/decide.php');
$say('');

$postTitle = 'Hello Milpa Process';
$draft = $registry->call('create_post', [
    'title' => $postTitle,
    'body' => 'The publish_post process, demonstrated live: draft -> review_gate -> published.',
], $agent);
if (!$draft->success) {
    $say("✘ create_post failed: {$draft->error}");

    exit(1);
}
$postId = $draft->data['id'];
$say("→ create_post(\"{$postTitle}\") … draft post #{$postId} created");

$start = $registry->call('process_instantiate', [
    'definition' => 'publish_post',
    // milpa/tool-runtime's #[Param(type: 'object')] takes `inputs` as a real object — a plain
    // associative array on the wire, no JSON-string workaround.
    'inputs' => ['post_id' => $postId],
], $agent);
if (!$start->success) {
    $say("✘ process_instantiate failed: {$start->data}");

    exit(1);
}
$instanceId = $start->data['instance_id'];
$say("→ process_instantiate(publish_post, {post_id: {$postId}}) … instance {$instanceId} at {$start->data['current_state']}");
$say("  requested by '{$agent->principal}' — the name of this terminal, not of a person: nobody verified who is typing");

if ($start->data['current_state'] !== 'review_gate') {
    $say("✘ expected the process to auto-advance to review_gate, landed on {$start->data['current_state']} instead.");

    exit(1);
}

$pending = null;
foreach ($registry->call('process_list_pending_approvals', [], $agent)->data['pending'] as $row) {
    if ($row['instance_id'] === $instanceId) {
        $pending = $row;
        break;
    }
}
if ($pending === null) {
    $say('✘ no pending decision found for this instance — the process did not reach review_gate.');

    exit(1);
}

// THE NEGATIVE CASE, ON PURPOSE. The agent can see the gate, knows its id, and can call the tool.
$say('');
$say('The agent wants its work shipped, so it tries to answer its own gate:');
$decision = ['instance_id' => $instanceId, 'gate_id' => $pending['gate_id'], 'decision' => 'grant'];

$asItself = $registry->call('process_submit_decision', $decision, $agent);
$say('→ process_submit_decision(grant) … ' . ($asItself->success ? 'ACCEPTED' : "REFUSED · {$asItself->error}"));
if (!$asItself->success) {
    $say("  {$asItself->data}");
}

// The old way around the rule: say you are somebody else. `process_submit_decision` used to take a
// `principal` argument and believe it. It takes none now, so the name is simply not read.
$asSomebodyElse = $registry->call('process_submit_decision', $decision + ['principal' => 'human:you'], $agent);
$say('→ process_submit_decision(grant, principal: "human:you") … ' . ($asSomebodyElse->success ? 'ACCEPTED' : "REFUSED · {$asSomebodyElse->error}"));
if (!$asSomebodyElse->success) {
    $say('  naming a person is not being one: the approver is whoever the house verified, never an argument');
}

/** @var RepositoryInterface<Post> $posts */
$posts = $kernel->container()->get(RepositoryInterface::class);
$status = $posts->find($postId)?->status;

if ($asItself->success || $asSomebodyElse->success || $status !== 'draft') {
    $say('');
    $say('✘ the agent approved its own work. That must never happen — this example is broken.');

    exit(1);
}

$say('');
$say("✔ post #{$postId} \"{$postTitle}\" is still a DRAFT and its gate is still open. The agent proposed; it does not dispose.");
$say('');
$say('  A person decides, in their own session, under their own signature:');
$say('');
$say('    php bin/enroll.php     once — makes your demo identity and enrolls it with the house');
$say('    php bin/decide.php     reads the gate, asks you, signs your answer');
$say('');

exit(0);
