<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\QueryRicerExtreme\HasIdentityMap;

/**
 * Maps to the shared `users` table. Declares no relation methods of its own —
 * the `dynamicPosts` relation is registered at runtime via
 * Model::resolveRelationUsing() in the test, so the relation-name guess sees a
 * closure frame rather than a named method.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 */
final class ResolverUser extends Model
{
    use HasIdentityMap;

    protected $table = 'users';

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'active'];
}
