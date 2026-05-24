<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature\Transaction;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Coverage\CoverageRegistry;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Store\TransactionJournal;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

final class TransactionJournalFeatureTest extends TestCase
{
    private IdentityMapStore $store;

    private CoverageRegistry $registry;

    private TransactionJournal $journal;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->registry = resolve(CoverageRegistry::class);
        $this->journal = resolve(TransactionJournal::class);
        $this->store->flush();
        $this->registry->flush();
        $this->journal->flush();
    }

    private function countSql(callable $callback): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $callback();
            $count = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }

        return $count;
    }

    #[Test]
    public function rollback_restores_pre_transaction_attribute_state(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $userId = $user->id;
        $this->store->flush();

        $fresh = User::find($userId);
        $this->assertInstanceOf(User::class, $fresh);
        $this->assertSame('Alice', $fresh->name);

        DB::beginTransaction();
        $fresh->name = 'Changed';
        $fresh->save();
        DB::rollBack();

        $again = User::find($userId);
        $this->assertInstanceOf(User::class, $again);
        $this->assertSame('Alice', $again->name);
        $this->assertSame($fresh, $again, 'identity preserved on restore');
    }

    #[Test]
    public function rollback_removes_models_created_inside_the_transaction(): void
    {
        DB::beginTransaction();
        $new = User::create(['name' => 'Temp', 'email' => 'temp@example.com']);
        $tempId = $new->id;
        DB::rollBack();

        $sql = $this->countSql(function () use ($tempId): void {
            $result = User::find($tempId);
            $this->assertNull($result);
        });

        $this->assertGreaterThan(0, $sql, 'find must hit the DB after rollback drops the entry');
    }

    #[Test]
    public function nested_savepoint_rollback_only_affects_inner_level(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $userId = $user->id;
        $this->store->flush();

        $fresh = User::find($userId);
        $this->assertInstanceOf(User::class, $fresh);

        DB::beginTransaction();
        $fresh->name = 'Outer';
        $fresh->save();

        DB::beginTransaction();
        $fresh->name = 'Inner';
        $fresh->save();
        DB::rollBack();

        $afterInner = User::find($userId);
        $this->assertInstanceOf(User::class, $afterInner);
        $this->assertSame('Outer', $afterInner->name);

        DB::rollBack();

        $afterOuter = User::find($userId);
        $this->assertInstanceOf(User::class, $afterOuter);
        $this->assertSame('Alice', $afterOuter->name);
    }

    #[Test]
    public function commit_keeps_in_transaction_writes_visible_in_map(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $userId = $user->id;
        $this->store->flush();

        $fresh = User::find($userId);
        $this->assertInstanceOf(User::class, $fresh);

        DB::beginTransaction();
        $fresh->name = 'Saved';
        $fresh->save();
        DB::commit();

        $again = User::find($userId);
        $this->assertInstanceOf(User::class, $again);
        $this->assertSame('Saved', $again->name);
    }

    #[Test]
    public function rollback_flushes_coverage_for_touched_model_classes_only(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => false]);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'active' => false]);
        $this->store->flush();
        $this->registry->flush();

        User::where('active', false)->get();
        $this->assertGreaterThan(0, $this->registry->entryCount(), 'baseline coverage recorded');

        DB::beginTransaction();
        User::where('active', false)->update(['active' => true]);
        DB::rollBack();

        $this->assertSame(0, $this->registry->entryCount(), 'coverage for touched class is flushed on rollback');
    }

    #[Test]
    public function rollback_with_inactive_journal_falls_back_to_full_flush(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $aliceId = $alice->id;
        $fresh = User::find($aliceId);
        $this->assertInstanceOf(User::class, $fresh);

        $this->journal->flush();

        DB::beginTransaction();
        $this->journal->flush();
        $fresh->name = 'Changed';
        $fresh->save();
        DB::rollBack();

        $sql = $this->countSql(function () use ($aliceId): void {
            User::find($aliceId);
        });

        $this->assertGreaterThan(0, $sql, 'inactive-journal rollback must wipe the map (forces SQL)');
    }

    #[Test]
    public function rollback_undoes_absent_records_taken_in_transaction(): void
    {
        $sql = $this->countSql(function (): void {
            User::find(999_999);
        });
        $this->assertGreaterThan(0, $sql);
        $this->store->flush();

        DB::beginTransaction();
        User::find(999_999);
        DB::rollBack();

        $sqlAfter = $this->countSql(function (): void {
            User::find(999_999);
        });

        $this->assertGreaterThan(0, $sqlAfter, 'absent record taken inside tx must be cleared on rollback');
    }

    #[Test]
    public function rollback_restores_entry_attribute_knowledge_not_just_model(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $userId = $user->id;
        $this->store->flush();

        $fresh = User::find($userId);
        $this->assertInstanceOf(User::class, $fresh);

        $entry = $this->store->findEntry($fresh);
        $this->assertNotNull($entry);
        $this->assertSame('Alice', $entry->attributes->get('name')?->currentValue);

        DB::beginTransaction();
        $fresh->name = 'Changed';
        $fresh->save();

        $entryAfterSave = $this->store->findEntry($fresh);
        $this->assertNotNull($entryAfterSave);
        $this->assertSame('Changed', $entryAfterSave->attributes->get('name')?->currentValue);

        DB::rollBack();

        $entryAfter = $this->store->findEntry($fresh);
        $this->assertNotNull($entryAfter);
        $factAfter = $entryAfter->attributes->get('name');
        $this->assertNotNull($factAfter);
        $this->assertSame(
            'Alice',
            $factAfter->currentValue,
            'entry attribute knowledge must be restored, not left in mutated state',
        );
        $this->assertSame(
            'Alice',
            $factAfter->originalValue,
            'entry attribute originalValue must be restored',
        );
    }

    #[Test]
    public function rollback_syncs_model_original_so_dirty_state_is_clean(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $userId = $user->id;
        $this->store->flush();

        $fresh = User::find($userId);
        $this->assertInstanceOf(User::class, $fresh);
        $this->assertFalse($fresh->isDirty());

        DB::beginTransaction();
        $fresh->name = 'Changed';
        $fresh->save();
        DB::rollBack();

        $this->assertFalse(
            $fresh->isDirty(),
            'after rollback the model must not be dirty — getOriginal() and getAttributes() must agree',
        );
        $this->assertSame(
            'Alice',
            $fresh->getOriginal('name'),
            'getOriginal() must return the pre-transaction value after rollback',
        );
    }

    #[Test]
    public function rollback_restores_every_entry_touched_in_transaction(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => false]);
        $bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'active' => false]);
        $this->store->flush();

        $aliceFresh = User::find($alice->id);
        $bobFresh = User::find($bob->id);
        $this->assertInstanceOf(User::class, $aliceFresh);
        $this->assertInstanceOf(User::class, $bobFresh);

        DB::beginTransaction();
        User::where('active', false)->update(['active' => true]);
        DB::rollBack();

        $this->assertFalse(
            (bool) $aliceFresh->active,
            'every entry visited by the mass-update must be restored, not only the first',
        );
        $this->assertFalse(
            (bool) $bobFresh->active,
            'every entry visited by the mass-update must be restored, not only the first',
        );
    }

    #[Test]
    public function rollback_restores_existing_entries_after_an_in_tx_creation_in_the_journal(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $aliceId = $alice->id;
        $this->store->flush();

        $aliceFresh = User::find($aliceId);
        $this->assertInstanceOf(User::class, $aliceFresh);

        DB::beginTransaction();
        User::create(['name' => 'Charlie', 'email' => 'charlie@example.com']);
        $aliceFresh->name = 'Modified';
        $aliceFresh->save();
        DB::rollBack();

        $this->assertSame(
            'Alice',
            $aliceFresh->name,
            'a journal entry created inside the tx must not short-circuit restoration of later entries',
        );
    }

    #[Test]
    public function store_constructed_without_journal_still_works(): void
    {
        $standalone = new IdentityMapStore;

        $user = User::create(['name' => 'Solo', 'email' => 'solo@example.com']);
        $standalone->remember($user, true);

        $entry = $standalone->findEntry($user);
        $this->assertNotNull($entry, 'store must function with no journal injected');
        $fact = $entry->attributes->get('name');
        $this->assertNotNull($fact);
        $this->assertSame('Solo', $fact->currentValue);
    }

    #[Test]
    public function rollback_re_indexes_unique_keys_so_subsequent_lookups_hit_memory(): void
    {
        config(['query-ricer-extreme.models' => [
            User::class => [
                'unique' => [['email']],
            ],
        ]]);

        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $aliceId = $alice->id;
        $this->store->flush();

        $loaded = User::find($aliceId);
        $this->assertInstanceOf(User::class, $loaded);

        DB::beginTransaction();
        $loaded->email = 'changed@example.com';
        $loaded->save();

        // Mid-tx lookup for the pre-tx email evicts that fingerprint from the index
        // (the entry's current email no longer matches the verification step).
        User::where('email', 'alice@example.com')->first();

        DB::rollBack();

        $sql = $this->countSql(function () use ($loaded): void {
            $afterRollback = User::where('email', 'alice@example.com')->first();
            $this->assertSame($loaded, $afterRollback);
        });

        $this->assertSame(
            0,
            $sql,
            'unique-key index must be refreshed after rollback so the pre-tx email still hits memory',
        );
    }

    #[Test]
    public function rollback_uses_connection_specific_journal_state(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $aliceId = $alice->id;
        $this->store->flush();

        $fresh = User::find($aliceId);
        $this->assertInstanceOf(User::class, $fresh);

        $this->journal->begin('other-connection');

        DB::beginTransaction();
        $fresh->name = 'Changed';
        $fresh->save();
        DB::rollBack();

        $this->assertSame(
            'Alice',
            $fresh->name,
            'rollback on the default connection must restore even while another connection has an open level',
        );

        $this->assertTrue(
            $this->journal->isActive('other-connection'),
            'the other connection\'s level must be untouched by the default-connection rollback',
        );

        $this->journal->flush();
    }
}
