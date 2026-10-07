<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Tests\Support;

use Milpa\ExampleBlog\Identity\ApproverKeyring;
use Milpa\ExampleBlog\Identity\EditorKey;
use Milpa\ExampleBlog\Identity\Gpg;
use Milpa\ExampleBlog\Identity\SignedCallDesk;
use Milpa\ExampleBlog\Identity\SignedCallOutcome;
use PHPUnit\Framework\Assert;

/**
 * One throwaway house and the people around it, for one test: a storage file, an event log, the
 * house's identity directory, and as many keyrings as the test needs people.
 *
 * Nobody in this suite builds a verified context by hand. A test that needs a human decision makes a
 * person here — a real key, generated for the test and deleted with it — and has them sign, so the
 * suite goes through the same desk the demo does and would notice if that desk stopped checking.
 */
final class Sandbox
{
    /** @var list<string> */
    private array $keyrings = [];

    private function __construct(public readonly string $dir)
    {
    }

    public static function create(): self
    {
        if (!Gpg::available()) {
            $why = 'gpg is not installed: a human decision in this example is an OpenPGP signature.';
            // A skipped guard guards nothing, so where the suite is the gate it fails instead.
            getenv('CI') !== false ? Assert::fail($why) : Assert::markTestSkipped($why);
        }

        // Short on purpose — see EditorKey::defaultKeyring() on gpg-agent's socket path.
        $dir = sys_get_temp_dir() . '/blog-t-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o700);

        return new self($dir);
    }

    public function storage(): string
    {
        return $this->dir . '/blog.db';
    }

    public function events(): string
    {
        return $this->dir . '/events.jsonl';
    }

    /** The house's side: its enrolled public keys and its spent signatures. */
    public function identity(): string
    {
        return $this->dir . '/identity';
    }

    public function keyring(string $person): string
    {
        return $this->dir . '/' . $person;
    }

    /** A person with a key of their own, NOT yet known to the house. */
    public function person(string $name): EditorKey
    {
        $key = new EditorKey($this->keyring($name));
        $key->generate("{$name} (test key) <{$name}@blog.invalid>");
        $this->keyrings[] = $key->keyring;

        return $key;
    }

    /** A person whose public key the house has enrolled. */
    public function editor(string $name = 'editor'): EditorKey
    {
        $key = $this->person($name);
        (new ApproverKeyring($this->identity() . '/approvers'))->enroll($key->publicKey());

        return $key;
    }

    /**
     * The person signs exactly this call and presents it at the desk — what `bin/decide.php` does.
     *
     * @param array<string, mixed> $arguments
     */
    public static function signedCall(SignedCallDesk $desk, EditorKey $who, string $operation, array $arguments): SignedCallOutcome
    {
        $payload = $desk->authorizationFor($operation, $arguments)->canonical();

        return $desk->call($operation, $arguments, $payload, $who->sign($payload));
    }

    /**
     * Runs one of the example's own scripts as a real process against this house — a session of its
     * own, sharing nothing with the test but the files on disk.
     *
     * Each script is handed only the paths it has a use for: the agent's scripts never learn where a
     * keyring is.
     *
     * @param list<string> $arguments
     * @param string       $stdin     what the person at that terminal types, if anything
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    public function run(string $script, array $arguments = [], string $stdin = ''): array
    {
        $paths = match ($script) {
            'enroll.php' => ['--identity=' . $this->identity(), '--keyring=' . $this->keyring('editor')],
            'decide.php' => [
                '--storage=' . $this->storage(),
                '--events=' . $this->events(),
                '--identity=' . $this->identity(),
                '--keyring=' . $this->keyring('editor'),
            ],
            default => ['--storage=' . $this->storage(), '--events=' . $this->events()],
        };
        if ($script === 'enroll.php' || $script === 'decide.php') {
            // These may start a gpg-agent for the editor's keyring; destroy() stops it.
            $this->keyrings[] = $this->keyring('editor');
        }

        $root = \dirname(__DIR__, 2);
        $process = proc_open(
            [\PHP_BINARY, $root . '/bin/' . $script, ...$paths, ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
        );
        Assert::assertIsResource($process, "failed to spawn bin/{$script}");
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    public function destroy(): void
    {
        foreach ($this->keyrings as $keyring) {
            (new Gpg($keyring))->stopAgent();
        }
        self::remove($this->dir);
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            self::remove($path . '/' . $entry);
        }
        @rmdir($path);
    }
}
