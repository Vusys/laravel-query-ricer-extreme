<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature\DataProviders;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Vusys\QueryRicerExtreme\Enums\EvaluationResult;
use Vusys\QueryRicerExtreme\Enums\FactConfidence;
use Vusys\QueryRicerExtreme\Enums\FactSource;
use Vusys\QueryRicerExtreme\Knowledge\AttributeFact;
use Vusys\QueryRicerExtreme\Knowledge\AttributeKnowledge;
use Vusys\QueryRicerExtreme\Predicate\PredicateEvaluator;
use Vusys\QueryRicerExtreme\Predicate\PredicateExtractor;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

#[Group('comprehensive')]
final class WhereShapeTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Basic comparison — all supported operators × several value types
    // -------------------------------------------------------------------------

    /** @return array<string, array{string, mixed, mixed, EvaluationResult}> */
    public static function basicComparisonProvider(): array
    {
        return [
            // String equality
            'eq string match' => ['=',  'alice@example.com', 'alice@example.com', EvaluationResult::Match],
            'eq string reject' => ['=',  'alice@example.com', 'bob@example.com',   EvaluationResult::Reject],
            'neq string match' => ['!=', 'alice@example.com', 'bob@example.com',   EvaluationResult::Match],
            'neq string reject' => ['!=', 'alice@example.com', 'alice@example.com', EvaluationResult::Reject],
            'diamond neq string match' => ['<>', 'alice@example.com', 'bob@example.com',   EvaluationResult::Match],
            'diamond neq string reject' => ['<>', 'alice@example.com', 'alice@example.com', EvaluationResult::Reject],

            // Integer equality
            'eq int match' => ['=',  1,       1,       EvaluationResult::Match],
            'eq int reject' => ['=',  1,       2,       EvaluationResult::Reject],
            'neq int match' => ['!=', 1,       2,       EvaluationResult::Match],
            'neq int reject' => ['!=', 1,       1,       EvaluationResult::Reject],
            'eq int zero match' => ['=',  0,       0,       EvaluationResult::Match],
            'eq int zero reject' => ['=',  0,       1,       EvaluationResult::Reject],
            'neq int zero match' => ['!=', 0,       1,       EvaluationResult::Match],
            'neq int zero reject' => ['!=', 0,       0,       EvaluationResult::Reject],
            'eq large int match' => ['=',  999999,  999999,  EvaluationResult::Match],
            'eq large int reject' => ['=',  999999,  999998,  EvaluationResult::Reject],

            // Float (loose == comparison)
            'eq float match' => ['=',  1.5,     1.5,     EvaluationResult::Match],
            'eq float reject' => ['=',  1.5,     2.5,     EvaluationResult::Reject],
            'neq float match' => ['!=', 1.5,     2.5,     EvaluationResult::Match],
            'neq float reject' => ['!=', 1.5,     1.5,     EvaluationResult::Reject],

            // Cross-type int↔bool — Unknown under ConservativeSemantics (the
            // default profile). Driver-aware profiles handle tinyint↔bool
            // coercion correctly in their own tests.
            'eq int bool same-shape unknown' => ['=',  1,       true,    EvaluationResult::Unknown],
            'eq int zero bool false unknown' => ['=',  0,       false,   EvaluationResult::Unknown],
            'eq int bool mismatched unknown' => ['=',  1,       false,   EvaluationResult::Unknown],
            'eq int zero bool true unknown' => ['=',  0,       true,    EvaluationResult::Unknown],

            // Null equality: SQL NULL comparisons always yield NULL (Unknown), never Match/Reject.
            // PHP loose equality would match null==null, null==0 etc., which is incorrect SQL semantics.
            'eq null null match' => ['=',  null,    null,    EvaluationResult::Unknown],
            'eq null string reject' => ['=',  'x',     null,    EvaluationResult::Unknown],
            'eq zero null match' => ['=',  0,       null,    EvaluationResult::Unknown],
            'neq null string match' => ['!=', 'x',     null,    EvaluationResult::Unknown],
            'neq null null reject' => ['!=', null,    null,    EvaluationResult::Unknown],

            // Empty string
            'eq empty string match' => ['=',  '',      '',      EvaluationResult::Match],
            'eq empty string reject' => ['=',  '',      'x',     EvaluationResult::Reject],
            'neq empty string match' => ['!=', '',      'x',     EvaluationResult::Match],
            'neq empty string reject' => ['!=', '',      '',      EvaluationResult::Reject],
        ];
    }

    #[DataProvider('basicComparisonProvider')]
    public function test_basic_comparison_node_evaluation(
        string $operator,
        mixed $storedValue,
        mixed $predicateValue,
        EvaluationResult $expected,
    ): void {
        $node = PredicateExtractor::fromWhere([
            'type' => 'Basic',
            'column' => 'email',
            'operator' => $operator,
            'value' => $predicateValue,
            'boolean' => 'and',
        ]);

        $this->assertNotNull($node);

        $evaluator = new PredicateEvaluator;
        $this->assertSame($expected, $evaluator->evaluate($this->knowledgeWith('email', $storedValue), $node));
    }

    // -------------------------------------------------------------------------
    // Unsupported operators → extractor returns null
    // -------------------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function unsupportedOperatorProvider(): array
    {
        return [
            'like' => ['LIKE'],
            'not like' => ['NOT LIKE'],
            'ilike' => ['ILIKE'],
        ];
    }

    #[DataProvider('unsupportedOperatorProvider')]
    public function test_unsupported_operator_extracts_to_null(string $operator): void
    {
        $node = PredicateExtractor::fromWhere([
            'type' => 'Basic',
            'column' => 'name',
            'operator' => $operator,
            'value' => 'Alice',
            'boolean' => 'and',
        ]);

        $this->assertNull($node);
    }

    // -------------------------------------------------------------------------
    // NULL / NOT NULL predicates
    // -------------------------------------------------------------------------

    /** @return array<string, array{string, mixed, EvaluationResult}> */
    public static function nullPredicateProvider(): array
    {
        return [
            'IS NULL — null stored matches' => ['Null',    null,  EvaluationResult::Match],
            'IS NULL — string stored rejects' => ['Null',    'x',   EvaluationResult::Reject],
            'IS NULL — zero int stored rejects' => ['Null',    0,     EvaluationResult::Reject],
            'IS NULL — false stored rejects' => ['Null',    false, EvaluationResult::Reject],
            'IS NOT NULL — null stored rejects' => ['NotNull', null,  EvaluationResult::Reject],
            'IS NOT NULL — string stored matches' => ['NotNull', 'x',   EvaluationResult::Match],
            'IS NOT NULL — zero int matches' => ['NotNull', 0,     EvaluationResult::Match],
            'IS NOT NULL — false stored matches' => ['NotNull', false, EvaluationResult::Match],
        ];
    }

    #[DataProvider('nullPredicateProvider')]
    public function test_null_predicate_evaluation(string $type, mixed $storedValue, EvaluationResult $expected): void
    {
        $node = PredicateExtractor::fromWhere([
            'type' => $type,
            'column' => 'deleted_at',
            'boolean' => 'and',
        ]);

        $this->assertNotNull($node);

        $evaluator = new PredicateEvaluator;
        $this->assertSame($expected, $evaluator->evaluate($this->knowledgeWith('deleted_at', $storedValue), $node));
    }

    // -------------------------------------------------------------------------
    // IN / NOT IN predicates — many shapes
    // -------------------------------------------------------------------------

    /** @return array<string, array{list<mixed>, bool, mixed, EvaluationResult}> */
    public static function inPredicateProvider(): array
    {
        return [
            // String values
            'in string match first' => [['alice@example.com', 'bob@example.com'], false, 'alice@example.com', EvaluationResult::Match],
            'in string match last' => [['bob@example.com', 'alice@example.com'], false, 'alice@example.com', EvaluationResult::Match],
            'in string reject' => [['bob@example.com', 'carol@example.com'], false, 'alice@example.com', EvaluationResult::Reject],
            'in string single match' => [['alice@example.com'],                    false, 'alice@example.com', EvaluationResult::Match],
            'in string single reject' => [['bob@example.com'],                      false, 'alice@example.com', EvaluationResult::Reject],
            // NOT IN string
            'not in string match' => [['bob@example.com', 'carol@example.com'], true,  'alice@example.com', EvaluationResult::Match],
            'not in string reject' => [['alice@example.com', 'bob@example.com'], true,  'alice@example.com', EvaluationResult::Reject],
            'not in string single match' => [['bob@example.com'],                      true,  'alice@example.com', EvaluationResult::Match],
            'not in string single reject' => [['alice@example.com'],                    true,  'alice@example.com', EvaluationResult::Reject],
            // Integer values
            'in int match' => [[1, 2, 3],            false, 1,  EvaluationResult::Match],
            'in int reject' => [[4, 5, 6],            false, 1,  EvaluationResult::Reject],
            'not in int match' => [[4, 5, 6],            true,  1,  EvaluationResult::Match],
            'not in int reject' => [[1, 2, 3],            true,  1,  EvaluationResult::Reject],
            'in int zero match' => [[0, 1, 2],            false, 0,  EvaluationResult::Match],
            'in int zero reject' => [[1, 2, 3],            false, 0,  EvaluationResult::Reject],
            // Large list
            'in large list match end' => [[1, 2, 3, 4, 5, 6, 7, 8, 9, 10], false, 10, EvaluationResult::Match],
            'in large list reject' => [[1, 2, 3, 4, 5, 6, 7, 8, 9, 10], false, 11, EvaluationResult::Reject],
            'not in large list match' => [[1, 2, 3, 4, 5, 6, 7, 8, 9, 10], true,  11, EvaluationResult::Match],
            'not in large list reject' => [[1, 2, 3, 4, 5, 6, 7, 8, 9, 10], true,  1,  EvaluationResult::Reject],
        ];
    }

    /**
     * @param  list<mixed>  $values
     */
    #[DataProvider('inPredicateProvider')]
    public function test_in_predicate_evaluation(array $values, bool $negated, mixed $storedValue, EvaluationResult $expected): void
    {
        $node = PredicateExtractor::fromWhere([
            'type' => $negated ? 'NotIn' : 'In',
            'column' => 'email',
            'values' => $values,
            'boolean' => 'and',
        ]);

        $this->assertNotNull($node);

        $evaluator = new PredicateEvaluator;
        $this->assertSame($expected, $evaluator->evaluate($this->knowledgeWith('email', $storedValue), $node));
    }

    // -------------------------------------------------------------------------
    // Unknown attribute — every node type must return Unknown
    // -------------------------------------------------------------------------

    /** @return array<string, array{array<string, mixed>}> */
    public static function unknownAttributeWhereProvider(): array
    {
        return [
            'Basic eq' => [['type' => 'Basic',   'column' => 'missing', 'operator' => '=',  'value' => 'x', 'boolean' => 'and']],
            'Basic neq' => [['type' => 'Basic',   'column' => 'missing', 'operator' => '!=', 'value' => 'x', 'boolean' => 'and']],
            'Null' => [['type' => 'Null',    'column' => 'missing', 'boolean' => 'and']],
            'NotNull' => [['type' => 'NotNull', 'column' => 'missing', 'boolean' => 'and']],
            'In' => [['type' => 'In',      'column' => 'missing', 'values' => ['x'],  'boolean' => 'and']],
            'NotIn' => [['type' => 'NotIn',   'column' => 'missing', 'values' => ['x'],  'boolean' => 'and']],
        ];
    }

    /** @param array<string, mixed> $where */
    #[DataProvider('unknownAttributeWhereProvider')]
    public function test_unknown_attribute_evaluates_to_unknown(array $where): void
    {
        $node = PredicateExtractor::fromWhere($where);
        $this->assertNotNull($node);

        $evaluator = new PredicateEvaluator;
        $this->assertSame(EvaluationResult::Unknown, $evaluator->evaluate(new AttributeKnowledge, $node));
    }

    // -------------------------------------------------------------------------
    // Unsupported where types → extractor returns null
    // -------------------------------------------------------------------------

    /** @return array<string, array{array<string, mixed>}> */
    public static function unsupportedWhereTypeProvider(): array
    {
        return [
            'Column' => [['type' => 'Column',  'column' => 'email', 'boolean' => 'and']],
            'Exists' => [['type' => 'Exists',  'column' => 'email', 'boolean' => 'and']],
            'Raw' => [['type' => 'Raw',     'column' => 'email', 'sql' => '1=1',    'boolean' => 'and']],
            'Nested' => [['type' => 'Nested',  'column' => 'email', 'boolean' => 'and']],
            'InRaw' => [['type' => 'InRaw',   'column' => 'email', 'values' => ['x'], 'boolean' => 'and']],
        ];
    }

    /** @param array<string, mixed> $where */
    #[DataProvider('unsupportedWhereTypeProvider')]
    public function test_unsupported_where_type_extracts_to_null(array $where): void
    {
        $this->assertNull(PredicateExtractor::fromWhere($where));
    }

    // -------------------------------------------------------------------------
    // Live query: key-set + predicate evaluated in memory (no SQL)
    // -------------------------------------------------------------------------

    /** @return array<string, array{mixed, bool, int}> */
    public static function livePredicateProvider(): array
    {
        return [
            'active=true, user active → result' => [true,  true,  0],
            'active=false, user active → empty' => [false, true,  0],
            'active=true, user inactive → empty' => [true,  false, 0],
            'active=false, user inactive → result' => [false, false, 0],
        ];
    }

    #[DataProvider('livePredicateProvider')]
    public function test_where_predicate_evaluated_from_cache(mixed $predicateValue, bool $userActive, int $expectedQueries): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice-'.uniqid().'@example.com', 'active' => $userActive]);
        resolve(IdentityMapStore::class)->flush();
        User::find($user->id);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $result = User::whereKey([$user->id])->where('active', $predicateValue)->get();

        $this->assertSame($expectedQueries, $queries);
        $this->assertCount($userActive === $predicateValue ? 1 : 0, $result);
    }

    // -------------------------------------------------------------------------
    // AND predicate: short-circuit semantics across multiple columns
    // -------------------------------------------------------------------------

    /** @return array<string, array{bool, bool, int}> */
    public static function andPredicateProvider(): array
    {
        return [
            'both match → 1 result' => [true,  true,  1],
            'first match second reject → 0' => [true,  false, 0],
            'first reject second match → 0' => [false, true,  0],
            'both reject → 0' => [false, false, 0],
        ];
    }

    #[DataProvider('andPredicateProvider')]
    public function test_and_predicate_short_circuits_on_reject(bool $matchActive, bool $matchName, int $expectedCount): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice-'.uniqid().'@example.com', 'active' => true]);
        resolve(IdentityMapStore::class)->flush();
        User::find($user->id);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $result = User::whereKey([$user->id])
            ->where('active', $matchActive)
            ->where('name', $matchName ? 'Alice' : 'NotAlice')
            ->get();

        $this->assertSame(0, $queries, 'Both columns known; must not hit SQL');
        $this->assertCount($expectedCount, $result);
    }

    // -------------------------------------------------------------------------
    // NULL predicate against live DB data
    // -------------------------------------------------------------------------

    /** @return array<string, array{string, bool}> */
    public static function nullPredicateLiveProvider(): array
    {
        return [
            'IS NULL on null deleted_at matches' => ['Null',    true],
            'IS NOT NULL on null deleted_at rejects' => ['NotNull', false],
        ];
    }

    #[DataProvider('nullPredicateLiveProvider')]
    public function test_null_predicate_against_live_data(string $type, bool $expectResult): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice-'.uniqid().'@example.com']);
        resolve(IdentityMapStore::class)->flush();
        User::find($user->id);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $result = $type === 'Null'
            ? User::whereKey([$user->id])->whereNull('deleted_at')->get()
            : User::whereKey([$user->id])->whereNotNull('deleted_at')->get();

        $this->assertSame(0, $queries);
        $this->assertCount($expectResult ? 1 : 0, $result);
    }

    // -------------------------------------------------------------------------
    // IN predicate against live DB data
    // -------------------------------------------------------------------------

    /** @return array<string, array{list<string>, bool, bool}> */
    public static function inPredicateLiveProvider(): array
    {
        return [
            'in names match' => [['Alice', 'Bob'],   false, true],
            'in names reject' => [['Bob', 'Charlie'], false, false],
            'not in names match' => [['Bob', 'Charlie'], true,  true],
            'not in names reject' => [['Alice', 'Bob'],   true,  false],
            'in single match' => [['Alice'],           false, true],
            'in single reject' => [['Bob'],             false, false],
            'not in single match' => [['Bob'],             true,  true],
            'not in single reject' => [['Alice'],           true,  false],
        ];
    }

    /**
     * @param  list<string>  $values
     */
    #[DataProvider('inPredicateLiveProvider')]
    public function test_in_predicate_against_live_data(array $values, bool $negated, bool $expectResult): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice-'.uniqid().'@example.com']);
        resolve(IdentityMapStore::class)->flush();
        User::find($user->id);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $result = $negated
            ? User::whereKey([$user->id])->whereNotIn('name', $values)->get()
            : User::whereKey([$user->id])->whereIn('name', $values)->get();

        $this->assertSame(0, $queries);
        $this->assertCount($expectResult ? 1 : 0, $result);
    }

    // -------------------------------------------------------------------------
    // orWhere always forces SQL fallthrough
    // -------------------------------------------------------------------------

    /** @return array<string, array{string, mixed}> */
    public static function orWhereProvider(): array
    {
        return [
            'orWhere string column' => ['name',   'Alice'],
            'orWhere bool column' => ['active',  true],
            'orWhere email column' => ['email',  'alice@example.com'],
        ];
    }

    #[DataProvider('orWhereProvider')]
    public function test_or_where_falls_through_to_sql(string $column, mixed $value): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice-'.uniqid().'@example.com', 'active' => true]);
        resolve(IdentityMapStore::class)->flush();
        User::find($user->id);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        User::query()->where('id', $user->id)->orWhere($column, $value)->first();

        $this->assertSame(1, $queries, 'orWhere must bypass identity map and execute SQL');
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function knowledgeWith(string $column, mixed $value): AttributeKnowledge
    {
        $attributes = new AttributeKnowledge;
        $attributes->facts[$column] = new AttributeFact(
            column: $column,
            originalValue: $value,
            currentValue: $value,
            isDirty: false,
            confidence: FactConfidence::Certain,
            source: FactSource::HydratedFromDatabase,
        );

        return $attributes;
    }
}
