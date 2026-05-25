<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use Rector\Set\ValueObject\SetList;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withPhpSets(php83: true)
    ->withSets([
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::TYPE_DECLARATION,
        SetList::EARLY_RETURN,
        SetList::PRIVATIZATION,
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
    ])
    // This rule fires against the installed Laravel version's reflection. We
    // support 11 / 12 / 13 and several write methods we override live on
    // Eloquent\Builder only in 13 (and on QueryBuilder via __call in 11/12).
    // Adding #[\Override] under the local install would fatal on installs that
    // don't have the method on the parent class. Skip the rule on the file
    // that hosts those overrides; methods elsewhere are still tagged.
    ->withSkip([
        AddOverrideAttributeToOverriddenMethodsRector::class => [
            __DIR__.'/src/Query/IdentityMapBuilder.php',
        ],
    ]);
