<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Vusys\QueryRicerExtreme\HasIdentityMap;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property bool $active
 * @property Carbon|null $deleted_at
 */
final class User extends Model
{
    use HasIdentityMap;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'active'];

    /** @var array<string, string> */
    protected $casts = [
        'active' => 'boolean',
    ];

    /** @return HasMany<Post, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /** @return MorphMany<Comment, $this> */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }
}
