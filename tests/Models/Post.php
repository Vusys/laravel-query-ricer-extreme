<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\QueryRicerExtreme\HasIdentityMap;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $tag_id
 * @property string $title
 * @property bool $published
 */
final class Post extends Model
{
    use HasIdentityMap;

    /** @var list<string> */
    protected $fillable = ['user_id', 'tag_id', 'title', 'published'];

    /** @var array<string, string> */
    protected $casts = ['published' => 'boolean'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Tag, $this> */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }
}
