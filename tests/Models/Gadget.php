<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Vusys\QueryRicerExtreme\HasIdentityMap;
use Vusys\QueryRicerExtreme\Tests\Concerns\UsesContextConnection;

/**
 * @property int $id
 * @property string $code
 * @property int $qty
 * @property-read string $label
 */
final class Gadget extends Model
{
    use HasIdentityMap;
    use UsesContextConnection;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['code', 'qty'];

    /** @var array<string, string> */
    protected $casts = ['qty' => 'integer'];

    /** @var list<string> */
    protected $appends = ['label'];

    /**
     * Classic accessor shadowing the real `code` column: reading $gadget->code
     * returns the upper-cased value, while the stored (and SQL-compared) value
     * stays lower-case.
     */
    /** @return Attribute<string, never> */
    protected function code(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): string => strtoupper(is_string($value) ? $value : ''),
        );
    }

    /**
     * Appended computed attribute — not a real column.
     *
     * @return Attribute<string, never>
     */
    protected function label(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $code = $this->attributes['code'] ?? '';

                return 'G-'.strtoupper(is_string($code) ? $code : '');
            },
        );
    }
}
