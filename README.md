# Laravel Query Ricer: Extreme

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

Absent-key tracking means the package remembers which primary keys returned `null` from a previous query. If those same keys are requested again under the same scope and conditions, no SQL is issued.

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

When a key-set query carries extra `where` conditions, the following are evaluated against cached attributes without touching the database:

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
