<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Identity;

/**
 * THE HUMAN'S SIDE: an OpenPGP key in the editor's own keyring, and the two things they do with it —
 * hand the public half to the house once, and sign the decisions they make.
 *
 * ⚠️ A DEMO IDENTITY. {@see self::generate()} makes a throwaway key with no passphrase so the example
 * runs without ceremony, and that missing passphrase is exactly what makes it a demo: with a real key
 * the signature is where the human is present — a passphrase typed, a card touched — and no script
 * can supply that for them. The key is generated on your machine when you ask for it and is never
 * part of this repository.
 *
 * What is NOT a demo is everything after the signature: the house verifies it with the framework's
 * own verifier and authorizer (see {@see SignedCallDesk}), the same code a real key goes through.
 */
final class EditorKey
{
    private readonly Gpg $gpg;

    public function __construct(public readonly string $keyring)
    {
        $this->gpg = new Gpg($keyring);
    }

    /**
     * Where the demo keeps the editor's keyring: OUTSIDE the house's `var/`, because a private key is
     * the person's and not the application's.
     *
     * The system temp directory, keyed by the checkout — short on purpose: gpg-agent listens on a
     * socket next to the keyring on machines without `/run/user`, and a socket path has a hard limit
     * of about a hundred characters.
     */
    public static function defaultKeyring(string $root): string
    {
        return sys_get_temp_dir() . '/blog-editor-' . substr(hash('sha256', $root), 0, 8);
    }

    /** The key's fingerprint, or null when this keyring holds no key yet. */
    public function fingerprint(): ?string
    {
        return $this->gpg->keys()[0]['fingerprint'] ?? null;
    }

    /**
     * Makes the throwaway key and returns its fingerprint.
     *
     * Ed25519, signing only, no passphrase, no expiry. The name is what a reader sees next to the
     * fingerprint in the log, so it says what the key is.
     */
    public function generate(string $name = 'Demo Editor (throwaway key) <editor@blog.invalid>'): string
    {
        try {
            [$exit, , $stderr] = $this->gpg->run([
                '--pinentry-mode', 'loopback', '--passphrase', '',
                '--quick-generate-key', $name, 'ed25519', 'sign', 'never',
            ]);
        } finally {
            $this->gpg->stopAgent();
        }

        $fingerprint = $exit === 0 ? $this->fingerprint() : null;
        if ($fingerprint === null) {
            throw new \RuntimeException('gpg could not generate the demo key: ' . trim($stderr));
        }

        return $fingerprint;
    }

    /** The public half, armored — the only part of this key the house ever receives. */
    public function publicKey(): string
    {
        [$exit, $armored, $stderr] = $this->gpg->run(['--armor', '--export', (string) $this->fingerprint()]);
        if ($exit !== 0 || $armored === '') {
            throw new \RuntimeException('gpg could not export the public key: ' . trim($stderr));
        }

        return $armored;
    }

    /**
     * A detached signature over exactly these bytes.
     *
     * The bytes are an authorization that names one call (see {@see SignedCallDesk::authorizationFor()}),
     * so what leaves here says "this decision, on this gate, in this house, now" and nothing wider.
     */
    public function sign(string $payload): string
    {
        $fingerprint = $this->fingerprint();
        if ($fingerprint === null) {
            throw new \RuntimeException("There is no key in '{$this->keyring}' to sign with — run `php bin/enroll.php` first.");
        }

        try {
            [$exit, $signature, $stderr] = $this->gpg->run([
                '--pinentry-mode', 'loopback', '--passphrase', '',
                '--local-user', $fingerprint, '--armor', '--detach-sign', '--output', '-',
            ], $payload);
        } finally {
            $this->gpg->stopAgent();
        }

        if ($exit !== 0 || $signature === '') {
            throw new \RuntimeException('gpg could not sign: ' . trim($stderr));
        }

        return $signature;
    }
}
