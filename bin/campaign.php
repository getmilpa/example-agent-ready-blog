#!/usr/bin/env php
<?php

declare(strict_types=1);

use Milpa\Data\RepositoryInterface;
use Milpa\EventStore\FileEventStore;
use Milpa\ExampleBlog\App\Kernel;
use Milpa\ExampleBlog\Blog\Post;
use Milpa\ExampleBlog\Orchestrator\Definitions\PublishCampaignProcess;
use Milpa\Orchestrator\ProcessInstance;
use Milpa\Runtime\Config;
use Milpa\ToolRuntime\Contracts\ToolContext;

require __DIR__ . '/../vendor/autoload.php';

// SESSION 1 OF 2 — THE AGENT PROPOSES A CAMPAIGN (the SUBPROCESS loop).
//
// Same two sessions as bin/process.php, one level deeper: the agent starts a publish_campaign PARENT,
// which runs publish_post as a CHILD subprocess, and the whole chain parks at the child's review gate.
// The agent then tries to answer that nested gate itself and is refused — wrapping a gate in a parent
// process does not make it the agent's to answer.
//
// A person decides it with the very same command, `php bin/decide.php`: the campaign is transparent,
// the inbox shows the leaf gate. On a grant the child publishes, its outcome routes up, and the
// campaign advances to announced -> done — decide.php prints that from the log.
//
// --storage=<file> and --events=<file> override where the house keeps its posts and its event log.
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
// The agent, as the house sees it: 'local-shell', an unsigned terminal. It is recorded as the
// requester of every gate this run opens — the parent's chain included.
$agent = ToolContext::cli();

$say('');
$say('milpa · example-agent-ready-blog — the SUBPROCESS loop · session 1 of 2: the agent proposes');
$say('process_instantiate(publish_campaign) → review[subprocess: publish_post] → child review_gate ⏸');
$say('  a person answers the child gate (php bin/decide.php) → child publishes → subprocess_done routes up → announced → done');
$say('');

$postTitle = 'Hello Milpa Campaign';
$draft = $registry->call('create_post', [
    'title' => $postTitle,
    'body' => 'The publish_campaign process, demonstrated live: a publish_post subprocess wrapped in an announce step.',
], $agent);
if (!$draft->success) {
    $say("✘ create_post failed: {$draft->error}");

    exit(1);
}
$postId = $draft->data['id'];
$say("→ create_post(\"{$postTitle}\") … draft post #{$postId} created");

$start = $registry->call('process_instantiate', [
    'definition' => PublishCampaignProcess::NAME,
    'inputs' => ['post_id' => $postId],
], $agent);
if (!$start->success) {
    $say("✘ process_instantiate failed: {$start->data}");

    exit(1);
}
$campaignId = $start->data['instance_id'];
$say("→ process_instantiate(publish_campaign, {post_id: {$postId}}) … campaign {$campaignId} at {$start->data['current_state']}");

if ($start->data['current_state'] !== PublishCampaignProcess::STATE_REVIEW) {
    $say("✘ expected the campaign to auto-advance to 'review' (its subprocess state), landed on {$start->data['current_state']} instead.");

    exit(1);
}

// The campaign's own stream records which child publish_post instance it started (the
// `SubprocessStarted` marker) — read it so this demo pins the EXACT child of THIS run, robust
// even when var/events.jsonl already holds gates from earlier runs.
/** @var Config $config */
$config = $kernel->container()->get(Config::class);
/** @var string $eventsPath */
$eventsPath = $config->get('orchestrator.events_path');
$store = new FileEventStore($eventsPath);
$childId = null;
foreach ($store->replay($campaignId) as $event) {
    if ($event->type === 'SubprocessStarted') {
        $childId = (string) ($event->payload['child_instance_id'] ?? '');
        break;
    }
}
if ($childId === null || $childId === '') {
    $say('✘ the campaign started no publish_post subprocess (no SubprocessStarted marker on its stream).');

    exit(1);
}

// Nested-gate discovery: the unified inbox surfaces the CHILD publish_post's review_gate, even
// though only the PARENT campaign was ever named to process_instantiate. The pending row's
// instance_id is the child's, NOT the campaign's — that is the whole point of the recursion.
$pending = null;
foreach ($registry->call('process_list_pending_approvals', [], $agent)->data['pending'] as $row) {
    if ($row['instance_id'] === $childId) {
        $pending = $row;
        break;
    }
}
if ($pending === null) {
    $say('✘ no nested child gate found — the campaign did not drive its publish_post subprocess to review_gate.');

    exit(1);
}
$say("→ process_list_pending_approvals … the pending gate belongs to CHILD publish_post {$childId} (the campaign {$campaignId} is waiting at 'review')");

// THE NEGATIVE CASE, ONE LEVEL DOWN. The gate the agent would have to answer is the child's, and the
// child's requester is still the agent: the engine carried it down the chain.
$say('');
$say('The agent tries to answer the nested gate itself:');
$attempt = $registry->call('process_submit_decision', [
    'instance_id' => $childId,
    'gate_id' => $pending['gate_id'],
    'decision' => 'grant',
], $agent);
$say('→ process_submit_decision(grant) on the child … ' . ($attempt->success ? 'ACCEPTED' : "REFUSED · {$attempt->error}"));
if (!$attempt->success) {
    $say("  {$attempt->data}");
}

// Reconstructed from a FRESH event store over the same append-only log — event-sourced through the
// recursion, no in-memory shortcut: the refusal left the parent exactly where it was.
$campaignState = (new ProcessInstance($campaignId, PublishCampaignProcess::build()))->currentState(new FileEventStore($eventsPath));
/** @var RepositoryInterface<Post> $posts */
$posts = $kernel->container()->get(RepositoryInterface::class);
$status = $posts->find($postId)?->status;

if ($attempt->success || $campaignState !== PublishCampaignProcess::STATE_REVIEW || $status !== 'draft') {
    $say('');
    $say('✘ the agent moved its own campaign past a human gate. That must never happen — this example is broken.');

    exit(1);
}

$say('');
$say("✔ post #{$postId} \"{$postTitle}\" is still a DRAFT; the campaign {$campaignId} keeps WAITING at '{$campaignState}'.");
$say('');
$say('  A person decides the child gate, in their own session, under their own signature:');
$say('');
$say('    php bin/enroll.php     once — makes your demo identity and enrolls it with the house');
$say('    php bin/decide.php     grant → the child publishes and the campaign runs on to done');
$say('');

exit(0);
