<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Tests\Identity;

use Milpa\ExampleBlog\Identity\ApproverKeyring;
use Milpa\ExampleBlog\Identity\EditorKey;
use Milpa\ExampleBlog\Tests\Support\Sandbox;
use PHPUnit\Framework\TestCase;

/**
 * The two halves of an identity, and where each one lives: the private half with the person, the
 * public half with the house.
 */
final class EditorKeyTest extends TestCase
{
    public function testThePersonsKeyringIsNotKeptInsideTheHouse(): void
    {
        $root = \dirname(__DIR__, 2);

        $keyring = EditorKey::defaultKeyring($root);

        // A private key is the person's, not the application's: nothing under the checkout holds it.
        $this->assertStringStartsNotWith($root, $keyring);
        // One keyring per checkout, the same one every run.
        $this->assertSame($keyring, EditorKey::defaultKeyring($root));
        $this->assertNotSame($keyring, EditorKey::defaultKeyring($root . '-another'));
        // gpg-agent binds a socket beside the keyring where there is no /run/user, and a socket path
        // is cut off at about a hundred characters — the longest name it uses has to fit.
        $this->assertLessThan(100, \strlen($keyring . '/S.gpg-agent.browser'));
    }

    public function testTheHouseReceivesThePublicHalfAndNothingItCouldSignWith(): void
    {
        $sandbox = Sandbox::create();

        try {
            $this->assertNull((new EditorKey($sandbox->keyring('editor')))->fingerprint(), 'no key before one is made');

            $editor = $sandbox->editor();

            $house = new ApproverKeyring($sandbox->identity() . '/approvers');
            $this->assertSame([$editor->fingerprint()], array_column($house->approvers(), 'fingerprint'));
            $this->assertStringContainsString('editor@blog.invalid', $house->approvers()[0]['uid']);

            // Pointed at the house's keyring, the same signing code has nothing to sign with: what
            // the house holds can check a signature and can never produce one.
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('gpg could not sign');
            (new EditorKey($house->directory))->sign('a decision the house would like to make for itself');
        } finally {
            $sandbox->destroy();
        }
    }
}
