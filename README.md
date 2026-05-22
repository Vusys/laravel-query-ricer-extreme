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

**Unique-key queries** — `first()`, `firstOrFail()`, `sole()`, and `exists()` can be served from memory when the column is declared unique in config:

```php
User::find(1); // email = alice@example.com now in map

User::where('email', 'alice@example.com')->first();   // no SQL
User::where('email', 'alice@example.com')->exists();  // no SQL — true
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
