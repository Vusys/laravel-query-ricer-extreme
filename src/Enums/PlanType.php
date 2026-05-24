<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Enums;

enum PlanType: string
{
    case ExecuteNormally = 'execute_normally';
    case ReturnModelFromMemory = 'return_model_from_memory';
    case ReturnNull = 'return_null';
    case ReturnCollectionFromMemory = 'return_collection_from_memory';
    case ReturnEmptyCollection = 'return_empty_collection';
    case RewritePrimaryKeysAndMerge = 'rewrite_primary_keys_and_merge';
    case RewritePredicateAndMerge = 'rewrite_predicate_and_merge';
    case ReturnScalarFromMemory = 'return_scalar_from_memory';
    case ReturnExistsFromMemory = 'return_exists_from_memory';
    case ReturnCountFromCoverage = 'return_count_from_coverage';
    case ReturnBelongsToFromMemory = 'return_belongs_to_from_memory';
    case FilterHasManyInMemory = 'filter_has_many_in_memory';
    case ReturnCollectionFromCoverage = 'return_collection_from_coverage';
    case ReturnExistsFromCoverage = 'return_exists_from_coverage';
    case ReturnPluckFromCoverage = 'return_pluck_from_coverage';
    case ReturnFirstFromCoverage = 'return_first_from_coverage';
    case ReturnSoleFromCoverage = 'return_sole_from_coverage';
    case ReturnSumFromCoverage = 'return_sum_from_coverage';
    case ReturnMinFromCoverage = 'return_min_from_coverage';
    case ReturnMaxFromCoverage = 'return_max_from_coverage';
    case ReturnAvgFromCoverage = 'return_avg_from_coverage';
}
