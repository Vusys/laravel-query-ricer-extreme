<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme;

use Vusys\QueryRicerExtreme\Enums\PlanType;

final readonly class Explanation implements \Stringable
{
    /**
     * @param  list<int|string>  $knownKeys
     * @param  list<int|string>  $missingKeys
     * @param  list<int|string>  $memoryKeys
     * @param  list<int|string>  $rejectedKeys
     */
    public function __construct(
        public PlanType $type,
        public string $modelClass,
        public string $reason,
        public bool $sqlExecuted,
        public array $knownKeys = [],
        public array $missingKeys = [],
        public array $memoryKeys = [],
        public array $rejectedKeys = [],
        public ?string $coverageRegion = null,
    ) {}

    public function __toString(): string
    {
        return implode("\n", array_filter([
            "Plan: {$this->type->value}",
            "Model: {$this->modelClass}",
            "Reason: {$this->reason}",
            $this->knownKeys !== [] ? 'Known keys: ['.implode(', ', $this->knownKeys).']' : null,
            $this->missingKeys !== [] ? 'Missing keys: ['.implode(', ', $this->missingKeys).']' : null,
            'SQL executed: '.($this->sqlExecuted ? 'yes' : 'no'),
        ]));
    }
}
