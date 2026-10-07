<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Identity;

use Milpa\ToolRuntime\Identity\AuthorizationVerdict;
use Milpa\ToolRuntime\ToolResult;

/**
 * What came of presenting a signed call at the desk: the framework's verdict on the signature, and —
 * only when that verdict granted — what the tool answered.
 *
 * Two answers kept apart because they are two different questions. "Did an enrolled person sign
 * exactly this?" is the desk's. "May that person do it?" is still the tool's: a verified editor who
 * opened a gate themselves is admitted here and refused there.
 */
final readonly class SignedCallOutcome
{
    public function __construct(
        public AuthorizationVerdict $verdict,
        public ?ToolResult $result = null,
    ) {
    }
}
