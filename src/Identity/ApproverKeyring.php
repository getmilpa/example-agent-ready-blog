<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Identity;

/**
 * THE HOUSE'S SIDE: the public keys this blog accepts a decision from, and nothing else.
 *
 * Enrolling is the whole of "who may answer a gate here". It holds public halves only — the house can
 * check a signature and can never make one — so an agent that reads every file this application owns
 * still finds nothing to sign with.
 */
final class ApproverKeyring
{
    private readonly Gpg $gpg;

    public function __construct(public readonly string $directory)
    {
        $this->gpg = new Gpg($directory);
    }

    /**
     * Adds a person to the people this house listens to.
     *
     * In this example anyone who can run `bin/enroll.php` can do it, which is what a single laptop
     * allows. In a real house enrolling an approver is itself an act of authority, done by someone
     * already trusted — the step you would guard hardest.
     */
    public function enroll(string $publicKey): void
    {
        [$exit, , $stderr] = $this->gpg->run(['--import'], $publicKey);
        if ($exit !== 0) {
            throw new \RuntimeException('gpg could not import the public key: ' . trim($stderr));
        }
    }

    /**
     * @return list<array{fingerprint: string, uid: string}>
     */
    public function approvers(): array
    {
        return $this->gpg->keys();
    }
}
