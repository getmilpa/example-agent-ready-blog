<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Tests\Orchestrator\Fixtures;

use Milpa\ExampleBlog\Orchestrator\DefinitionContract;

/**
 * Minimal hand-built {@see DefinitionContract}: `draft --submit--> review`, nothing beyond it.
 * Stands in for the real `ProcessDefinition` (arriving in a later task) so the {@see
 * \Milpa\ExampleBlog\Orchestrator\Reducer} can be proven against a definition shape before that
 * richer implementation exists.
 */
final class StubDefinition implements DefinitionContract
{
    public function initialState(): string
    {
        return 'draft';
    }

    /**
     * @return list<array{name: string, to: string}>
     */
    public function transitionsFrom(string $state): array
    {
        return match ($state) {
            'draft' => [['name' => 'submit', 'to' => 'review']],
            default => [],
        };
    }
}
