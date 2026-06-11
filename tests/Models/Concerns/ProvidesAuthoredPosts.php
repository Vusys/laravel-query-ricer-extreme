<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Vusys\QueryRicerExtreme\Tests\Models\Post;

/**
 * A relation method hosted in a trait rather than directly on the model, to
 * exercise the relation-name guess for trait-copied methods.
 *
 * @phpstan-require-extends Model
 */
trait ProvidesAuthoredPosts
{
    /** @return HasMany<Post, $this> */
    public function authoredPosts(): HasMany
    {
        return $this->hasMany(Post::class, 'user_id');
    }
}
