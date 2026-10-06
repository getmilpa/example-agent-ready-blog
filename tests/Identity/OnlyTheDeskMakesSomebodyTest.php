<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Tests\Identity;

use PHPUnit\Framework\TestCase;

/**
 * THE HOST'S HALF OF THE CONTRACT, held by a test.
 *
 * The process engine refuses to let a transport's placeholder answer a gate — `local-shell`,
 * `stdio`, `mcp` — and takes any OTHER principal the host hands it as somebody the host verified.
 * It has no way to tell: whether a principal was verified is something only the host knows. So one
 * line in a script — `ToolContext::stdio($id, 'human:editor')` — would make an MCP agent an
 * approver, and nothing in the engine would object.
 *
 * This application's answer is that exactly one place builds a context that names somebody: the
 * {@see \Milpa\ExampleBlog\Identity\SignedCallDesk}, after a signature verified. Every other context
 * in `src/` and `bin/` is a transport's own name. That is a claim about the whole codebase, so it is
 * checked against the whole codebase.
 */
final class OnlyTheDeskMakesSomebodyTest extends TestCase
{
    /** Where a context may be built from a signer, and why each is allowed. */
    private const array MAY_NAME_A_SIGNER = [
        'src/Identity/SignedCallDesk.php', // the real one: after a signature verified
        'src/App/Demo.php',                // the first loop's stand-in, which says on screen that it is one
    ];

    public function testNoSourceFileOrScriptNamesAPersonByHand(): void
    {
        $root = \dirname(__DIR__, 2);
        $offences = [];

        foreach (['src', 'bin'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $relative = substr($file->getPathname(), \strlen($root) + 1);
                foreach (self::contextsBuiltIn((string) file_get_contents($file->getPathname())) as [$how, $arguments]) {
                    $allowed = match ($how) {
                        // A terminal, as itself.
                        'cli', 'tui' => true,
                        // An MCP pipe, as itself: the request id and NOTHING else — the second
                        // argument is a principal.
                        'stdio' => $arguments === 1,
                        'authorizedBy' => \in_array($relative, self::MAY_NAME_A_SIGNER, true),
                        // `new ToolContext(...)`, web(), mcp(), telegram(): each takes a principal
                        // from whoever is calling it.
                        default => false,
                    };
                    if (!$allowed) {
                        $offences[] = "{$relative}: ToolContext {$how}() with {$arguments} argument(s)";
                    }
                }
            }
        }

        $this->assertSame([], $offences, 'a context that names somebody is built only by the desk, after a signature verified');
    }

    public function testTheScanSeesWhatItIsLookingFor(): void
    {
        // The instrument, measured: each way of naming somebody by hand is found, and the comment
        // that merely mentions one is not.
        $found = self::contextsBuiltIn(<<<'PHP'
            <?php
            // ToolContext::web('nobody', []) in a comment is not a call
            $a = ToolContext::cli();
            $b = ToolContext::stdio((string) ($request['id'] ?? uniqid('mcp-', true)));
            $c = ToolContext::stdio($id, 'human:editor');
            $d = new ToolContext(principal: 'human:editor', channel: 'web');
            $e = ToolContext::web('human:editor', ['*']);
            $f = \Milpa\ToolRuntime\Contracts\ToolContext::authorizedBy($signer, $scopes);
            PHP);

        $this->assertSame([
            ['cli', 0],
            ['stdio', 1],
            ['stdio', 2],
            ['new', 2],
            ['web', 2],
            ['authorizedBy', 2],
        ], $found);
    }

    /**
     * Every place `$code` builds a ToolContext: the factory used (or `new`) and how many arguments
     * it was given. Read from PHP's own tokens, so comments and strings cannot be mistaken for code.
     *
     * @return list<array{0: string, 1: int}>
     */
    private static function contextsBuiltIn(string $code): array
    {
        $tokens = array_values(array_filter(
            token_get_all($code),
            static fn (array|string $token): bool => !\is_array($token) || !\in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true),
        ));
        $text = static fn (array|string $token): string => \is_array($token) ? $token[1] : $token;
        $namesTheClass = static fn (array|string $token): bool => \is_array($token)
            && \in_array($token[0], [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true)
            && preg_match('/(^|\\\\)ToolContext$/', $token[1]) === 1;

        $built = [];
        foreach ($tokens as $i => $token) {
            if (!$namesTheClass($token)) {
                continue;
            }
            $before = $tokens[$i - 1] ?? '';
            $after = $tokens[$i + 1] ?? '';
            if (\is_array($before) && $before[0] === \T_NEW && $text($after) === '(') {
                $built[] = ['new', self::argumentsFrom($tokens, $i + 1)];
            } elseif (\is_array($after) && $after[0] === \T_DOUBLE_COLON && $text($tokens[$i + 3] ?? '') === '(') {
                $built[] = [$text($tokens[$i + 2]), self::argumentsFrom($tokens, $i + 3)];
            }
        }

        return $built;
    }

    /**
     * How many arguments the call opening at `$tokens[$open]` was given: commas at its own depth.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function argumentsFrom(array $tokens, int $open): int
    {
        $depth = 0;
        $commas = 0;
        $anything = false;
        for ($i = $open, $n = \count($tokens); $i < $n; ++$i) {
            $token = \is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
            if (\in_array($token, ['(', '[', '{'], true)) {
                ++$depth;
            } elseif (\in_array($token, [')', ']', '}'], true)) {
                if (--$depth === 0) {
                    break;
                }
            } elseif ($depth === 1) {
                $anything = true;
                $commas += $token === ',' ? 1 : 0;
            }
            if ($depth > 1) {
                $anything = true;
            }
        }

        return $anything ? $commas + 1 : 0;
    }
}
