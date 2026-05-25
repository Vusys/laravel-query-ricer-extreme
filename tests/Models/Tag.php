<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Vusys\QueryRicerExtreme\HasIdentityMap;
use Vusys\QueryRicerExtreme\Tests\Concerns\UsesContextConnection;
use Vusys\QueryRicerExtreme\Tests\Factories\TagFactory;

/**
 * @property int $id
 * @property string $name
 * @property int $priority
 * @property string|null $color
 */
final class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    use HasIdentityMap;
    use UsesContextConnection;

    /** @var list<string> */
    protected $fillable = ['name', 'priority', 'color'];

    /** @var array<string, string> */
    protected $casts = [
        'priority' => 'integer',
    ];

    protected static function newFactory(): TagFactory
    {
        return TagFactory::new();
    }

    /** @return BelongsToMany<Post, $this> */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class)
            ->withPivot(['active', 'priority'])
            ->withTimestamps();
    }
}
