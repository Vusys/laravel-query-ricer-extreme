<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Knowledge;

use Illuminate\Database\Eloquent\Model;
use Vusys\QueryRicerExtreme\Enums\FactConfidence;
use Vusys\QueryRicerExtreme\Enums\FactSource;

final class AttributeKnowledge
{
    /** @var array<string, AttributeFact> */
    public array $facts = [];

    public bool $allColumnsKnown = false;

    public function knows(string $column): bool
    {
        return isset($this->facts[$column]);
    }

    public function get(string $column): ?AttributeFact
    {
        return $this->facts[$column] ?? null;
    }

    public function set(string $column, AttributeFact $fact): void
    {
        $this->facts[$column] = $fact;
    }

    public function recordFromModel(Model $model, bool $isFullSelect): void
    {
        if ($isFullSelect) {
            $this->allColumnsKnown = true;
        }

        foreach ($model->getAttributes() as $column => $value) {
            $this->facts[$column] = new AttributeFact(
                column: $column,
                originalValue: $value,
                currentValue: $value,
                isDirty: false,
                confidence: FactConfidence::Certain,
                source: FactSource::HydratedFromDatabase,
            );
        }
    }

    public function mergeFromSaved(Model $model): void
    {
        foreach ($model->getAttributes() as $column => $value) {
            if (isset($this->facts[$column])) {
                $this->facts[$column]->originalValue = $value;
                $this->facts[$column]->currentValue = $value;
                $this->facts[$column]->isDirty = false;
                $this->facts[$column]->confidence = FactConfidence::Certain;
                $this->facts[$column]->source = FactSource::HydratedFromDatabase;
            } else {
                $this->facts[$column] = new AttributeFact(
                    column: $column,
                    originalValue: $value,
                    currentValue: $value,
                    isDirty: false,
                    confidence: FactConfidence::Certain,
                    source: FactSource::HydratedFromDatabase,
                );
            }
        }
    }

    public function syncFromModel(Model $model): void
    {
        $currentAttributes = $model->getAttributes();

        foreach ($this->facts as $column => $fact) {
            if (! array_key_exists($column, $currentAttributes)) {
                continue;
            }

            $fact->currentValue = $currentAttributes[$column];
            $fact->isDirty = $model->isDirty($column);
        }
    }

    /** @param list<string>|array<int, string> $columns */
    public function satisfies(array $columns): bool
    {
        if ($columns === ['*']) {
            return $this->allColumnsKnown;
        }

        foreach ($columns as $column) {
            if ($column === '*') {
                return $this->allColumnsKnown;
            }

            if (! $this->knows($column)) {
                return false;
            }
        }

        return true;
    }
}
