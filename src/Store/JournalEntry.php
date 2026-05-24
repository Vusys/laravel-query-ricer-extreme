<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Store;

final readonly class JournalEntry
{
    /**
     * @param  array<string, mixed>|null  $modelOriginal  raw attributes captured at snapshot time;
     *                                                    used to restore the model's in-memory state
     *                                                    on rollback. Null when no model existed.
     */
    public function __construct(
        public string $entryKey,
        public string $modelClass,
        public ?IdentityEntry $before,
        public bool $wasAbsent,
        public ?array $modelOriginal,
    ) {}
}
