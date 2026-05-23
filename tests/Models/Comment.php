<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Vusys\QueryRicerExtreme\HasIdentityMap;

/**
 * @property int $id
 * @property string $commentable_type
 * @property int $commentable_id
 * @property string $body
 */
final class Comment extends Model
{
    use HasIdentityMap;

    /** @var list<string> */
    protected $fillable = ['commentable_type', 'commentable_id', 'body'];

    /** @return MorphTo<Model, $this> */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}
