<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QueryRicerExtreme\Enums\PlanType;
use Vusys\QueryRicerExtreme\Explanation;

final class ExplanationTest extends TestCase
{
    #[Test]
    public function to_string_includes_core_fields_and_omits_empty_key_lines(): void
    {
        $explanation = new Explanation(
            type: PlanType::ReturnModelFromMemory,
            modelClass: 'App\\User',
            reason: 'pk-hit',
            sqlExecuted: false,
        );

        $expected = implode("\n", [
            'Plan: return_model_from_memory',
            'Model: App\\User',
            'Reason: pk-hit',
            'SQL executed: no',
        ]);

        $this->assertSame($expected, (string) $explanation);
    }

    #[Test]
    public function to_string_includes_known_and_missing_key_lines_when_non_empty(): void
    {
        $explanation = new Explanation(
            type: PlanType::RewritePrimaryKeysAndMerge,
            modelClass: 'App\\User',
            reason: 'partial-key-set',
            sqlExecuted: true,
            knownKeys: [1, 2],
            missingKeys: [3],
        );

        $expected = implode("\n", [
            'Plan: rewrite_primary_keys_and_merge',
            'Model: App\\User',
            'Reason: partial-key-set',
            'Known keys: [1, 2]',
            'Missing keys: [3]',
            'SQL executed: yes',
        ]);

        $this->assertSame($expected, (string) $explanation);
    }

    #[Test]
    public function to_string_includes_only_known_keys_line_when_missing_keys_empty(): void
    {
        $explanation = new Explanation(
            type: PlanType::ReturnCollectionFromMemory,
            modelClass: 'App\\Post',
            reason: 'all-in-memory',
            sqlExecuted: false,
            knownKeys: [10, 20, 30],
        );

        $expected = implode("\n", [
            'Plan: return_collection_from_memory',
            'Model: App\\Post',
            'Reason: all-in-memory',
            'Known keys: [10, 20, 30]',
            'SQL executed: no',
        ]);

        $this->assertSame($expected, (string) $explanation);
    }

    #[Test]
    public function to_array_returns_every_field_under_its_snake_case_key(): void
    {
        $explanation = new Explanation(
            type: PlanType::RewritePrimaryKeysAndMerge,
            modelClass: 'App\\User',
            reason: 'partial-key-set',
            sqlExecuted: true,
            knownKeys: [1, 2],
            missingKeys: [3],
            memoryKeys: [4, 5],
            rejectedKeys: [6],
            coverageRegion: 'active = true',
        );

        $this->assertSame([
            'type' => 'rewrite_primary_keys_and_merge',
            'model_class' => 'App\\User',
            'reason' => 'partial-key-set',
            'sql_executed' => true,
            'known_keys' => [1, 2],
            'missing_keys' => [3],
            'memory_keys' => [4, 5],
            'rejected_keys' => [6],
            'coverage_region' => 'active = true',
        ], $explanation->toArray());
    }
}
