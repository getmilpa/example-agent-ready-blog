<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Identity;

use Milpa\ToolRuntime\Identity\GnupgSignatureVerifier;
use Milpa\ToolRuntime\Identity\SignatureVerifier;
use Milpa\ToolRuntime\Identity\VerifiedSigner;

/**
 * tool-runtime's own OpenPGP verifier, held to THIS house's keyring.
 *
 * {@see GnupgSignatureVerifier} asks gpg who signed some bytes, and gpg answers from a keyring — by
 * default the `~/.gnupg` of whoever runs the process. Left there, every public key that person ever
 * imported for any reason would count as an approver of this blog. So the house's keyring is named
 * for the length of one verification, and the environment is put back as it was.
 *
 * This class verifies nothing itself. The verdict is the framework's; this only says which keyring it
 * is read from.
 */
final class HouseKeyringVerifier implements SignatureVerifier
{
    public function __construct(
        private readonly ApproverKeyring $approvers,
        private readonly SignatureVerifier $gpg = new GnupgSignatureVerifier(),
    ) {
    }

    public function verify(string $payload, string $signature): ?VerifiedSigner
    {
        $before = getenv('GNUPGHOME');
        putenv('GNUPGHOME=' . $this->approvers->directory);

        try {
            return $this->gpg->verify($payload, $signature);
        } finally {
            putenv($before === false ? 'GNUPGHOME' : 'GNUPGHOME=' . $before);
        }
    }
}
