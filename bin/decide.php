#!/usr/bin/env php
<?php

declare(strict_types=1);

use Milpa\Data\RepositoryInterface;
use Milpa\EventStore\FileEventStore;
use Milpa\ExampleBlog\App\Kernel;
use Milpa\ExampleBlog\Blog\Post;
use Milpa\ExampleBlog\Identity\EditorKey;
use Milpa\ExampleBlog\Identity\Gpg;
use Milpa\Runtime\Config;
use Milpa\ToolRuntime\Contracts\ToolContext;

require __DIR__ . '/../vendor/autoload.php';

// SESSION 2 OF 2 — A PERSON DECIDES.
//
// The agent's session (bin/process.php, bin/campaign.php, or an MCP host on bin/mcp-server.php) left a
// gate open and could not answer it. This is the other session: a person reads what is waiting, says
// grant or reject, and SIGNS that one decision with their own key. The house checks the signature and
// only then runs process_submit_decision — as the signer, not as this terminal.
//
// Nothing in this script names an approver. There is no `principal` to pass: who decided is whoever's
// key signed, read back by the house from the signature itself.
//
//   php bin/decide.php                 ask at the prompt
//   php bin/decide.php --grant         decide without a prompt (what CI runs)
//   php bin/decide.php --reject
//   php bin/decide.php --instance=<id> pick which waiting gate, when there are several
//
// --storage, --events, --identity and --keyring override where the house and the editor keep their
// files; the test suite uses them to run this against a throwaway house.
$options = getopt('', ['grant', 'reject', 'instance:', 'storage:', 'events:', 'identity:', 'keyring:']);
$option = static fn (string $name): ?string => is_string($options[$name] ?? null) ? $options[$name] : null;
$decision = isset($options['grant']) ? 'grant' : (isset($options['reject']) ? 'reject' : null);

$say = static function (string $line): void {
    fwrite(STDOUT, $line . PHP_EOL);
};
$fail = static function (string $line): never {
    fwrite(STDERR, $line . PHP_EOL);

    exit(1);
};

if (isset($options['grant'], $options['reject'])) {
    $fail('✘ --grant and --reject together say nothing. Pick one.');
}
if (!Gpg::available()) {
    $fail('✘ gpg is not installed. A human decision in this example is an OpenPGP signature — install GnuPG and run this again.');
}

$kernel = Kernel::boot($option('storage'), $option('events'), $option('identity'));
$registry = $kernel->registry();
$desk = $kernel->desk();

$editor = new EditorKey($option('keyring') ?? EditorKey::defaultKeyring(dirname(__DIR__)));
$fingerprint = $editor->fingerprint();
if ($fingerprint === null) {
    $fail('✘ you have no key yet, so there is nobody for the house to recognise. Run:  php bin/enroll.php');
}

$say('');
$say('milpa · example-agent-ready-blog — the PROCESS loop · session 2 of 2: a person decides');
$say('');
$say("You are {$fingerprint} — a DEMO identity (see bin/enroll.php).");
$say('');

// Reading the inbox is free on a local shell — whoever holds one can already read the files. It is
// ANSWERING that takes an identity.
$pendingList = $registry->call('process_list_pending_approvals', [], ToolContext::cli());
/** @var list<array{instance_id: string, gate_id: string, artifact: array{data: array<string, mixed>}}> $waiting */
$waiting = $pendingList->data['pending'];
if ($waiting === []) {
    $say('Nothing is waiting for a decision. Let the agent propose something first:  php bin/process.php');
    $say('');

    exit(0);
}

$wanted = $option('instance');
$pending = null;
foreach ($waiting as $row) {
    // With no --instance, the last row wins: the inbox lists instances oldest first, so that is
    // the newest one still waiting.
    if ($wanted === null || $row['instance_id'] === $wanted) {
        $pending = $row;
    }
}
if ($pending === null) {
    $fail("✘ no gate is waiting on instance '{$wanted}'.");
}
$instanceId = $pending['instance_id'];

/** @var Config $config */
$config = $kernel->container()->get(Config::class);
/** @var string $eventsPath */
$eventsPath = $config->get('orchestrator.events_path');
$store = new FileEventStore($eventsPath);

// Who asked is on the log, written when the gate opened — read it, do not assume it.
$requester = '?';
$postId = null;
foreach ($store->replay($instanceId) as $event) {
    if ($event->type === 'ProcessStarted') {
        $postId = $event->payload['post_id'] ?? null;
    }
    if ($event->type === 'GateOpened') {
        $requester = (string) ($event->payload['requester'] ?? '?');
    }
}

$others = count($waiting) - 1;
$say("Waiting for you: instance {$instanceId} · gate {$pending['gate_id']} · requested by '{$requester}'"
    . ($others > 0 ? "  (+{$others} more — pick one with --instance=<id>)" : ''));
$say('---');
$artifact = $pending['artifact']['data'];
$say((string) $artifact['title']);
$say('');
$say((string) $artifact['excerpt']);
$say('');
/** @var array<string, string> $labels */
$labels = $artifact['labels'];
foreach ($labels as $label => $transition) {
    $say(sprintf('[%s] %s (%s)', strtoupper($label), $label, $transition));
}
$say('---');
$say('');

if ($decision === null) {
    fwrite(STDOUT, '? Your decision — [g]rant / [r]eject: ');
    $answer = strtolower(trim((string) fgets(STDIN)));
    $decision = in_array($answer, ['g', 'grant'], true) ? 'grant' : 'reject';
    $say('');
}

// THE SIGNATURE NAMES THE CALL. Not "yes", not "approve whatever is pending": this decision, on this
// gate, of this instance, in this house, now, once. These bytes are what the key signs.
$arguments = ['instance_id' => $instanceId, 'gate_id' => $pending['gate_id'], 'decision' => $decision];
$signed = $desk->authorizationFor('process_submit_decision', $arguments)->canonical();
$say('→ you sign exactly this call, and nothing wider:');
$say('  ' . $signed);
$signature = $editor->sign($signed);

$firstNewSeq = $store->nextSeq();
$outcome = $desk->call('process_submit_decision', $arguments, $signed, $signature);

if (!$outcome->verdict->granted || $outcome->verdict->signer === null || $outcome->result === null) {
    $fail('✘ the house did not accept the signature: ' . $outcome->verdict->reason);
}
$say('→ the house checked it: an enrolled key signed it · it names this call · it is fresh · it was never used');
$say('  ✔ signed by ' . $outcome->verdict->signer->principal());

$result = $outcome->result;
if (!$result->success) {
    $fail("✘ {$result->error} — {$result->data}");
}

// What the decision moved, read back from a FRESH store over the same append-only log: the decision
// itself (and who made it), and everything the engine did because of it — including, for a campaign,
// the outcome routing up to the parent.
$say('→ process_submit_decision ran as that signer. On the log:');
$moved = [];
$definitionOf = [];
foreach ((new FileEventStore($eventsPath))->replayAll() as $streamId => $stream) {
    $definitionOf[$streamId] = (string) ($stream[0]->payload['_definition'] ?? 'process');
    foreach ($stream as $event) {
        if ($event->seq >= $firstNewSeq) {
            $moved[$event->seq] = $event;
        }
    }
}
ksort($moved);
foreach ($moved as $event) {
    $detail = match (true) {
        isset($event->payload['by']) => 'by ' . $event->payload['by'],
        isset($event->payload['state']) => (string) $event->payload['state'],
        isset($event->payload['requester']) => "requested by '{$event->payload['requester']}'",
        default => '',
    };
    $say(sprintf(
        '  ⚡ %s %s  %s%s',
        $definitionOf[$event->streamId],
        substr($event->streamId, 0, 8),
        $event->type,
        $detail === '' ? '' : " — {$detail}",
    ));
}
$say('');

/** @var RepositoryInterface<Post> $posts */
$posts = $kernel->container()->get(RepositoryInterface::class);
$post = is_int($postId) ? $posts->find($postId) : null;
$about = $post !== null ? "Post #{$post->id} \"{$post->title}\" is " . ($post->status === 'published' ? 'now PUBLISHED' : 'still a DRAFT') . '.' : '';

$state = $result->data['current_state'];
if ($decision === 'grant') {
    $say("✔ GRANT — instance {$instanceId} reached '{$state}'. {$about}");
} else {
    $say("✘ REJECT — instance {$instanceId} is back at '{$state}', with a fresh gate open for the revision. {$about}");
}
$say('');

exit(0);
