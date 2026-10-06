#!/usr/bin/env php
<?php

declare(strict_types=1);

use Milpa\ExampleBlog\Identity\ApproverKeyring;
use Milpa\ExampleBlog\Identity\EditorKey;
use Milpa\ExampleBlog\Identity\Gpg;

require __DIR__ . '/../vendor/autoload.php';

// ONCE, BEFORE ANY DECISION: a person tells the house who they are.
//
// The process loop asks a human to answer a gate, and "a human" has to mean somebody the house can
// recognise — not a name typed into an argument. Here that somebody is an OpenPGP key: this script
// makes a throwaway one in the editor's OWN keyring and hands the house its public half. From then on
// the house can check that a decision was signed by that key, and can never sign one itself.
//
// Two optional overrides, used by the test suite: --identity=<dir> (the house's side, defaults to
// var/identity) and --keyring=<dir> (the editor's side, defaults to a directory outside this project).
$options = getopt('', ['identity:', 'keyring:']);
$root = dirname(__DIR__);
$identity = is_string($options['identity'] ?? null) ? $options['identity'] : $root . '/var/identity';
$keyring = is_string($options['keyring'] ?? null) ? $options['keyring'] : EditorKey::defaultKeyring($root);

$say = static function (string $line): void {
    fwrite(STDOUT, $line . PHP_EOL);
};

if (!Gpg::available()) {
    fwrite(STDERR, '✘ gpg is not installed. A human decision in this example is an OpenPGP signature, checked by the gpg on your machine — install GnuPG and run this again.' . PHP_EOL);

    exit(1);
}

$say('');
$say('milpa · example-agent-ready-blog — enrolling the person who decides');
$say('');

$editor = new EditorKey($keyring);
$fingerprint = $editor->fingerprint();
if ($fingerprint === null) {
    $fingerprint = $editor->generate();
    $say('→ generated a throwaway OpenPGP key for you (ed25519, signing only)');
} else {
    $say('→ you already have a demo key — using it');
}

$approvers = new ApproverKeyring($identity . '/approvers');
$approvers->enroll($editor->publicKey());
$say('→ the house enrolled its public half: it can now CHECK your signature, and can never make one');
$say('');

foreach ($approvers->approvers() as $approver) {
    $mark = $approver['fingerprint'] === $fingerprint ? '✔' : '·';
    $say("{$mark} {$approver['fingerprint']}  {$approver['uid']}");
}

$say('');
$shown = str_starts_with($identity, $root . '/') ? substr($identity, strlen($root) + 1) : $identity;
$say("  your keyring (private half): {$keyring}");
$say("  the house's (public halves):  {$shown}/approvers");
$say('');
$say('This is a DEMO identity. The key has no passphrase, so signing asks nothing of you — which is');
$say('the one thing a real key would never allow: there, the signature is the moment a person is');
$say('present (a passphrase typed, a card touched). It was generated on this machine just now and');
$say('is not part of the repository. Everything that happens AFTER the signature is the real path.');
$say('');
$say('Next:  php bin/process.php   (the agent proposes)   →   php bin/decide.php   (you decide)');
$say('');

exit(0);
