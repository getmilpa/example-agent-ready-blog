<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Identity;

/**
 * Runs the `gpg` already on the machine against ONE keyring, named on every call.
 *
 * `--homedir` is never left to the environment. Without it gpg reads `~/.gnupg`, and a demo that
 * signed with — or trusted — whatever keys the person running it happens to own would be spending a
 * real identity to play a pretend one.
 */
final class Gpg
{
    public function __construct(
        private readonly string $keyring,
        private readonly string $binary = 'gpg',
    ) {
    }

    /** Whether there is a gpg to ask at all — the one thing this example needs beyond PHP. */
    public static function available(string $binary = 'gpg'): bool
    {
        try {
            [$exit] = self::exec([$binary, '--version'], '');
        } catch (\RuntimeException) {
            return false;
        }

        return $exit === 0;
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    public function run(array $arguments, string $stdin = ''): array
    {
        // Created here, with the mode gpg insists on, so gpg never creates it itself: a keyring gpg
        // sets up on its own may come with a keybox daemon, and this one needs no daemon to be read.
        if (!is_dir($this->keyring) && !@mkdir($this->keyring, 0o700, true) && !is_dir($this->keyring)) {
            throw new \RuntimeException("Cannot create the keyring directory '{$this->keyring}'.");
        }

        return self::exec([$this->binary, '--homedir', $this->keyring, '--batch', '--no-tty', ...$arguments], $stdin);
    }

    /**
     * The keys in this keyring, read from gpg's machine-readable listing — never from the text it
     * prints for people, which changes with the locale.
     *
     * @return list<array{fingerprint: string, uid: string}>
     */
    public function keys(): array
    {
        if (!is_dir($this->keyring)) {
            return [];
        }

        [, $listing] = $this->run(['--list-keys', '--with-colons']);

        $keys = [];
        $current = null;
        foreach (preg_split('/\R/', $listing) ?: [] as $line) {
            $field = explode(':', $line);
            if ($field[0] === 'pub') {
                $current = \count($keys);
                $keys[$current] = ['fingerprint' => '', 'uid' => ''];
            } elseif ($field[0] === 'sub') {
                // A subkey has a fingerprint record of its own; the identity is the primary key's.
                $current = null;
            } elseif ($current !== null && $field[0] === 'fpr' && $keys[$current]['fingerprint'] === '') {
                $keys[$current]['fingerprint'] = $field[9] ?? '';
            } elseif ($current !== null && $field[0] === 'uid' && $keys[$current]['uid'] === '') {
                $keys[$current]['uid'] = $field[9] ?? '';
            }
        }

        return array_values(array_filter($keys, static fn (array $key): bool => $key['fingerprint'] !== ''));
    }

    /**
     * Stops the agent gpg starts to hold a private key, so a run leaves no daemon behind.
     *
     * Only generating and signing start one; reading and verifying need no agent.
     */
    public function stopAgent(): void
    {
        try {
            self::exec(['gpgconf', '--homedir', $this->keyring, '--kill', 'gpg-agent'], '');
        } catch (\RuntimeException) {
            // No gpgconf, no agent to stop.
        }
    }

    /**
     * @param list<string> $command
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private static function exec(array $command, string $stdin): array
    {
        // An argument list, never a shell string: a keyring path or a name with a space or a quote in
        // it stays one argument.
        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($process)) {
            throw new \RuntimeException("Cannot run '{$command[0]}' — is it installed?");
        }

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
