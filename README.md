# Laravel Query Ricer: Extreme

> Rice your Eloquent queries until the database wonders where everybody went.

A scoped Eloquent identity map, process-truth engine, and query-elision planner for Laravel.

## What it does

After Eloquent hydrates a model, that model becomes a known fact for the current request, job, or process scope. When another Eloquent query is about to execute, the package asks:

> Can this query be partially or fully answered from the models we already hold in memory?

```php
$userA = User::find(1);
$userB = User::find(1);

$userA === $userB; // true — no second query
```

It can also answer `whereKey` queries from memory:

```php
User::find(1);

User::query()->whereKey(1)->first(); // memory — no SQL
```

This is not a cache. It is an **identity map plus query-elision planner**. Within a configured scope, hydrated model instances are the source of truth for their own known attributes and loaded relations.

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
$explanations = IdentityMap::explain(fn () => User::find(1));
// Plan: return_model_from_memory
// Model: App\Models\User
// Reason: exact-primary-key-hit
// SQL executed: no
```

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
