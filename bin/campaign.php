#!/usr/bin/env php
<?php

declare(strict_types=1);

use Milpa\EventStore\FileEventStore;
use Milpa\ExampleBlog\App\Kernel;
use Milpa\ExampleBlog\Blog\PostStorageInterface;
use Milpa\ExampleBlog\Orchestrator\Definitions\PublishCampaignProcess;
use Milpa\Orchestrator\ProcessInstance;
use Milpa\Runtime\Config;
use Milpa\ToolRuntime\Contracts\ToolContext;

require __DIR__ . '/../vendor/autoload.php';

// The interactive walkthrough for the SUBPROCESS loop (mirrors bin/process.php's plain loop):
// instantiate a publish_campaign PARENT process, which runs publish_post as a CHILD subprocess,
// render the CHILD's DecisionArtifact to THIS terminal, let a human grant/reject it, and — on
// grant — watch the child publish, the outcome route back up, and the campaign advance to
// announced -> done. --auto-approve / --reject make it non-interactive for CI.
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
// The instantiating principal: 'cli'. The human who resolves the CHILD's gate below uses a
// DIFFERENT principal ('human:you') so the demo never trips the anti-self-approval invariant.
$ctx = ToolContext::cli();

$say('');
$say('milpa · example-agent-ready-blog — the SUBPROCESS loop, live (recursive composition)');
$say('process_instantiate(publish_campaign) → review[subprocess: publish_post] → child review_gate');
$say('  → human decides → child publishes → subprocess_done routes up → announced → done');
$say('');

$postTitle = 'Hello Milpa Campaign';
$draft = $registry->call('create_post', [
    'title' => $postTitle,
    'body' => 'The publish_campaign process, demonstrated live: a publish_post subprocess wrapped in an announce step.',
], $ctx);
if (!$draft->success) {
    $say("✘ create_post failed: {$draft->error}");

    exit(1);
}
$postId = $draft->data['id'];
$say("→ create_post(\"{$postTitle}\") … draft post #{$postId} created");

$start = $registry->call('process_instantiate', [
    'definition' => PublishCampaignProcess::NAME,
    'inputs' => ['post_id' => $postId],
], $ctx);
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
$pendingList = $registry->call('process_list_pending_approvals', [], $ctx);
$pending = null;
foreach ($pendingList->data['pending'] as $row) {
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

$say('');
$say('The CHILD\'s decision artifact — the campaign is transparent; the human resolves only the');
$say('leaf publish_post gate, exactly as in bin/process.php (rendering is the consumer\'s half):');
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
    'instance_id' => $childId,
    'gate_id' => $pending['gate_id'],
    'decision' => $decision,
    'principal' => 'human:you',
], $ctx);
if (!$submit->success) {
    $say("✘ process_submit_decision failed: {$submit->data}");

    exit(1);
}

// The submit() acts on the CHILD, so its returned current_state is the CHILD's. To prove the
// PARENT campaign advanced, we reconstruct it from a FRESH event store over the same append-only
// log — event-sourced through the recursion, no in-memory shortcut.
$freshStore = new FileEventStore($eventsPath);
$campaignState = (new ProcessInstance($campaignId, PublishCampaignProcess::build()))->currentState($freshStore);

/** @var PostStorageInterface $storage */
$storage = $kernel->container()->get(PostStorageInterface::class);
$post = $storage->find($postId);
$postStatus = $post !== null ? strtoupper($post->status) : 'UNKNOWN';

if ($decision === 'grant') {
    $say("✔ GRANT — child publish_post {$childId} reached '{$submit->data['current_state']}'. Post #{$postId} \"{$postTitle}\" is now {$postStatus}.");
    $say("  subprocess_done routed up: campaign {$campaignId} advanced to '{$campaignState}' (a fresh replay of the parent stream confirms it).");
    if ($campaignState !== PublishCampaignProcess::STATE_DONE || $postStatus !== 'PUBLISHED') {
        $say('✘ expected the campaign at DONE and the post PUBLISHED.');

        exit(1);
    }
} else {
    $stillPending = $registry->call('process_list_pending_approvals', [], $ctx);
    $childGates = array_filter(
        $stillPending->data['pending'],
        static fn (array $row): bool => $row['instance_id'] !== $campaignId,
    );
    $say("✘ REJECT — child publish_post {$childId} is back at '{$submit->data['current_state']}' (a fresh child gate is open for revision). Post #{$postId} is still {$postStatus}.");
    $say("  The campaign {$campaignId} keeps WAITING at '{$campaignState}' — the subprocess never finished, so no subprocess_done routed up. " . \count($childGates) . ' child gate still pending.');
    if ($campaignState !== PublishCampaignProcess::STATE_REVIEW || $childGates === []) {
        $say('✘ expected the campaign still waiting at review with the child gate re-opened.');

        exit(1);
    }
}
$say('');

exit(0);
