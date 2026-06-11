<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Vusys\QueryRicerExtreme\HasIdentityMap;
use Vusys\QueryRicerExtreme\Tests\Models\Concerns\ProvidesAuthoredPosts;

/**
 * Maps to the shared `users` table. Its only relation, `authoredPosts`, is
 * defined in a trait — proving the relation-name guess survives trait hosting.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 */
final class TraitHostedUser extends Model
{
    use HasIdentityMap;
    use ProvidesAuthoredPosts;

    protected $table = 'users';

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'active'];

    /**
     * A second hasMany to Post with the SAME signature as authoredPosts. Two
     * relation methods sharing one structural signature must never produce a
     * divergent result, even though the name memo can only hold one name per
     * signature.
     *
     * @return HasMany<Post, $this>
     */
    public function everyPost(): HasMany
    {
        return $this->hasMany(Post::class, 'user_id');
    }
}
