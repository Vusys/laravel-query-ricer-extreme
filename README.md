# Laravel Query Ricer: Extreme

[![Tests](https://github.com/Vusys/laravel-query-ricer-extreme/actions/workflows/tests.yml/badge.svg)](https://github.com/Vusys/laravel-query-ricer-extreme/actions/workflows/tests.yml) [![codecov](https://codecov.io/gh/Vusys/laravel-query-ricer-extreme/graph/badge.svg)](https://codecov.io/gh/Vusys/laravel-query-ricer-extreme) [![tests](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/Vusys/laravel-query-ricer-extreme/badges/tests.json)](https://github.com/Vusys/laravel-query-ricer-extreme/actions/workflows/tests.yml) [![assertions](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/Vusys/laravel-query-ricer-extreme/badges/assertions.json)](https://github.com/Vusys/laravel-query-ricer-extreme/actions/workflows/tests.yml) [![test LOC](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/Vusys/laravel-query-ricer-extreme/badges/test-ratio.json)](tests/) [![CI matrix](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/Vusys/laravel-query-ricer-extreme/badges/matrix.json)](.github/workflows/tests.yml) [![Bencher](https://img.shields.io/badge/Bencher-tracked-FD6F1B?logo=data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0id2hpdGUiPjxwYXRoIGQ9Ik0xMiAyTDMgN3YxMGw5IDUgOS01VjdaIi8+PC9zdmc+)](https://bencher.dev/perf/vusys-laravel-query-ricer-extreme) [![Mutation testing](https://img.shields.io/endpoint?style=flat&url=https://badge-api.stryker-mutator.io/github.com/Vusys/laravel-query-ricer-extreme/master)](https://dashboard.stryker-mutator.io/reports/github.com/Vusys/laravel-query-ricer-extreme/master) [![OpenSSF Scorecard](https://api.scorecard.dev/projects/github.com/Vusys/laravel-query-ricer-extreme/badge)](https://scorecard.dev/viewer/?uri=github.com/Vusys/laravel-query-ricer-extreme) [![PHP](https://img.shields.io/badge/php-%5E8.3-777BB4?logo=php&logoColor=white)](composer.json) [![Laravel](https://img.shields.io/badge/laravel-11%20%7C%2012%20%7C%2013-FF2D20?logo=laravel)](composer.json) [![PHPStan](https://img.shields.io/badge/PHPStan-level%209-brightgreen.svg)](phpstan.neon) [![Rector](https://img.shields.io/badge/Rector-passing-brightgreen.svg)](rector.php) [![Code Style: Pint](https://img.shields.io/badge/code%20style-Laravel%20Pint-FF2D20.svg?logo=laravel)](https://github.com/laravel/pint) [![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

> Rice your Eloquent queries until the database wonders where everybody went.

A scoped Eloquent identity map, process-truth engine, and query-elision planner for Laravel.

## What it does

After Eloquent hydrates a model, that model becomes a known fact for the current request, job, or process scope. When another Eloquent query is about to execute, the package asks:

> Can this query be partially or fully answered from the models we already hold in memory?

**Exact primary key** — zero SQL if already in memory:

```php
$userA = User::find(1);
$userB = User::find(1);

$userA === $userB; // true — no second query
```

**Key-set queries** — only unknown keys hit the database:

```php
User::find([1, 2, 3, 4]);
// Already in map: 1, 2. Confirmed absent: 3.
// SQL: SELECT * FROM users WHERE id IN (4)
// Result merged in original-key order: [user#1, user#2, null, user#4]
```

**Predicate evaluation** — extra `where` conditions evaluated in memory before SQL:

```php
// Map already holds user#1 (active=1) and user#2 (active=0).
User::whereKey([1, 2, 3])->where('active', true)->get();
// user#1: active == true → Match, returned from memory
// user#2: active == false → Reject, excluded without SQL
// user#3: not in map → queried
// SQL: SELECT * FROM users WHERE id IN (3) AND active = 1
```

**Unique-key queries** — `first()`, `firstOrFail()`, `sole()`, `value()`, and `exists()` can be served from memory when the column is declared unique in config:

```php
User::find(1); // email = alice@example.com now in map

User::where('email', 'alice@example.com')->first();          // no SQL
User::where('email', 'alice@example.com')->value('email');   // no SQL
User::where('email', 'alice@example.com')->exists();         // no SQL — true
```

**Relation resolution** — `belongsTo`, `morphTo`, `hasMany`, and `morphMany` are also served from memory when possible:

```php
$post = Post::find(1);   // User already in the map from earlier work

$post->user;             // no SQL — cached User returned directly

$user->load('posts');
$user->posts()->where('published', true)->get();   // no SQL — filtered in memory
```

Absent-key tracking means the package remembers which primary keys and unique-key values returned nothing from a previous query. If those same lookups are repeated under the same scope, no SQL is issued.

This is not a cache. It is an **identity map plus query-elision planner**. Within a configured scope, hydrated model instances are the source of truth for their own known attributes.

## Installation

```bash
composer require vusys/laravel-query-ricer-extreme
```

## Usage

### Opt in per model

```php
use Vusys\QueryRicerExtreme\HasIdentityMap;

final class User extends Model
{
    use HasIdentityMap;
}
```

### Per-query opt-out

```php
User::query()->withoutIdentityMap()->find(1);
```

### Manual flush

```php
use Vusys\QueryRicerExtreme\IdentityMap;

IdentityMap::flush();               // all entries
IdentityMap::flush(User::class);    // one model class
IdentityMap::forget($user);         // one instance
```

### Disable for a scope

```php
IdentityMap::disabled(function () {
    return User::find(1); // always hits DB
});
```

### Unique-key lookups

Declare unique indexes in the published config to allow `where('col', value)->first()` style queries to be answered from memory:

```php
// config/query-ricer-extreme.php
'models' => [
    App\Models\User::class => [
        'unique' => [
            ['email'],
            ['tenant_id', 'slug'],  // compound key
        ],
    ],
],
```

Once configured, any of the following can be served without SQL when the model is already in the identity map:

```php
User::where('email', 'alice@example.com')->first();
User::where('email', 'alice@example.com')->firstOrFail();
User::where('email', 'alice@example.com')->sole();
User::where('email', 'alice@example.com')->value('email');
User::where('email', 'alice@example.com')->exists();
```

Additional `where` conditions on top of the unique key are evaluated in memory using the same predicate engine used for key-set queries. If the extra predicate can be evaluated and the unique key match is rejected, the package returns `null` or `false` with no SQL — because the unique key guarantees there can be no other row with that value.

```php
// Map holds user#1: email = alice@example.com, active = true
User::where('email', 'alice@example.com')->where('active', false)->first();
// → null, no SQL. The unique key found the only candidate; active = false rejects it.
```

Absence is also tracked: if a unique-key lookup returns `null` from the database, subsequent identical lookups skip SQL until the model is created and remembered.

### Explain a query decision

```php
$explanations = IdentityMap::explain(fn () => User::whereKey([1, 2, 3])->where('active', true)->get());
// Plan: rewrite_predicate_and_merge
// Model: App\Models\User
// Memory keys: [1, 2]   ← evaluated in-memory (match or reject)
// SQL keys: [3]          ← unknown, sent to database
// Rejected keys: [2]     ← predicate evaluated to Reject
```

## Supported in-memory predicates

The following are evaluated against cached attributes without touching the database. They apply both as extra conditions on top of a key-set or unique-key query, and as the basis for determining whether a unique-key candidate matches:

| Eloquent method | Operators |
|---|---|
| `where($col, $val)` / `where($col, '=', $val)` | `=` |
| `where($col, '!=', $val)` / `where($col, '<>', $val)` | `!=`, `<>` |
| `whereIn($col, [...])` | `IN` |
| `whereNotIn($col, [...])` | `NOT IN` |
| `whereNull($col)` | `IS NULL` |
| `whereNotNull($col)` | `IS NOT NULL` |
| Multiple `where` chained with `AND` | AND-tree |

Anything the package cannot evaluate in memory falls through to SQL unchanged — unsupported operators (`>`, `<`, `LIKE`, `BETWEEN`), raw `whereRaw` clauses, `orWhere` conditions, and attributes not present on a partially loaded model.

### Relation optimisations

When `HasIdentityMap` is applied, four relation types gain memory-backed implementations. They fall back to SQL transparently on any condition the package cannot safely evaluate in memory.

**`belongsTo` / `morphTo`** — resolved without SQL when the related model is already in the identity map:

```php
$post->user;       // no SQL if User#N is already in the map
$comment->owner;   // no SQL for polymorphic relations too
```

Fallback to SQL when: FK is null, the related model class does not use `HasIdentityMap`, the entry is absent from the map, or the query has joins, unions, groups, havings, or a lock.

**`hasMany` / `morphMany`** — when the parent's relation is already completely loaded, additional `where` constraints are evaluated in memory:

```php
$user->load('posts');                                    // marks the relation complete in the map

$user->posts()->get();                                   // no SQL — full cached collection
$user->posts()->where('published', true)->get();         // no SQL — filtered in memory
```

Fallback to SQL when: the relation has not been loaded, the relation was loaded with extra constraints (constrained eager load), the query adds joins/unions/groups/havings/lock/offset/limit, the predicate cannot be evaluated in memory, or any member of the loaded collection has left the identity map.

## Scope model

The identity map is flushed automatically:

- **HTTP request** — at termination via `app()->terminating()`
- **Queue job** — before and after each job via `JobProcessing` / `JobProcessed` / `JobFailed` events

## Publish config

```bash
php artisan vendor:publish --tag=query-ricer-extreme-config
```

## Requirements

- PHP 8.3+
- Laravel 11, 12, or 13

## License

MIT
