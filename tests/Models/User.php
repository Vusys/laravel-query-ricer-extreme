<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Vusys\QueryRicerExtreme\HasIdentityMap;
use Vusys\QueryRicerExtreme\Tests\Concerns\UsesContextConnection;
use Vusys\QueryRicerExtreme\Tests\Factories\UserFactory;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property bool $active
 * @property int|null $score
 * @property string|null $bio
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
final class User extends Model
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasIdentityMap;
    use SoftDeletes;
    use UsesContextConnection;

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'active', 'score', 'bio'];

    /** @var array<string, string> */
    protected $casts = [
        'active' => 'boolean',
        'score' => 'integer',
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
