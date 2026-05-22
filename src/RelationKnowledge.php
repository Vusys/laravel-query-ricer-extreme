<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme;

final class RelationKnowledge
{
    /** @var array<string, RelationFact> */
    public array $relations = [];

    public function isLoaded(string $name): bool
    {
        return isset($this->relations[$name]) && $this->relations[$name]->loaded;
    }

    public function get(string $name): ?RelationFact
    {
        return $this->relations[$name] ?? null;
    }

    public function set(string $name, RelationFact $fact): void
    {
        $this->relations[$name] = $fact;
    }
}
