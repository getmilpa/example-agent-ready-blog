<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Identity;

use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Identity\AuthorizationVerdict;
use Milpa\ToolRuntime\Identity\NonceLedger;
use Milpa\ToolRuntime\Identity\OperationAuthorization;
use Milpa\ToolRuntime\Identity\OperationAuthorizer;
use Milpa\ToolRuntime\ToolRegistry;

/**
 * The desk where a person's signed call enters the house — and THE ONLY PLACE in this application
 * where a verified identity is made.
 *
 * Everything else here runs under a context that names a transport: `local-shell` for a terminal,
 * `stdio` for an MCP pipe. Those are honest names for "whoever is holding this", and the process
 * tools treat them that way: such a caller may start a process, and may not answer its gate. A gate
 * is answered by somebody, and the only way to be somebody here is to present a signature this desk
 * accepts.
 *
 * It accepts one when all of these hold, and they are the framework's rules, not this example's
 * ({@see OperationAuthorizer}):
 *
 * 1. an ENROLLED key signed it — the house's keyring, not the keyring of whoever runs the script;
 * 2. the signed bytes name THIS call — this operation, these arguments, this house;
 * 3. it is FRESH — two minutes, so a captured signature is worthless tomorrow;
 * 4. it is UNUSED — the same bytes do not work twice.
 *
 * Only then is the call run, under {@see ToolContext::authorizedBy()}: the principal becomes the
 * key's fingerprint, which is what lands in the log as who decided.
 */
final class SignedCallDesk
{
    private readonly OperationAuthorizer $authorizer;

    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly ApproverKeyring $approvers,
        NonceLedger $spent,
        private readonly string $house,
    ) {
        $this->authorizer = new OperationAuthorizer(new HouseKeyringVerifier($approvers), $spent);
    }

    /**
     * The exact thing a person is asked to sign: this operation, these arguments, this house, now,
     * once.
     *
     * Its {@see OperationAuthorization::canonical()} bytes are what the key signs. Nothing about them
     * is secret — the agent can read every field — and that is fine: what the agent cannot do is
     * produce a signature over them.
     *
     * @param array<string, mixed> $arguments
     */
    public function authorizationFor(string $operation, array $arguments): OperationAuthorization
    {
        return new OperationAuthorization(
            operation: $operation,
            arguments: $arguments,
            host: $this->house,
            issuedAt: gmdate('c'),
            nonce: bin2hex(random_bytes(16)),
        );
    }

    /**
     * Runs `$operation` as whoever signed it, or refuses and says why.
     *
     * `$arguments` is what is actually about to run; the authorizer rebuilds the authorization from
     * it and compares byte for byte, so a signature over "reject" cannot be presented with "grant".
     *
     * @param array<string, mixed> $arguments
     */
    public function call(string $operation, array $arguments, string $signedPayload, string $signature): SignedCallOutcome
    {
        $tool = $this->registry->getDefinition($operation);
        if ($tool === null) {
            return new SignedCallOutcome(AuthorizationVerdict::denied("this house has no operation named '{$operation}'"));
        }
        if ($this->approvers->approvers() === []) {
            return new SignedCallOutcome(AuthorizationVerdict::denied('nobody is enrolled in this house yet, so no signature can be recognised — run `php bin/enroll.php`'));
        }

        $verdict = $this->authorizer->authorize($operation, $arguments, $this->house, $signedPayload, $signature, time());
        if (!$verdict->granted || $verdict->signer === null) {
            return new SignedCallOutcome($verdict);
        }

        // The grant is exactly as wide as what was signed for: the operation's own scopes, never `*`.
        $context = ToolContext::authorizedBy($verdict->signer, $tool->scopes);

        return new SignedCallOutcome($verdict, $this->registry->call($operation, $arguments, $context));
    }
}
