<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\NormalizesName;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    use BelongsToTenant, HasUlids, NormalizesName;

    /**
     * Le principal et l'activité ne changent que par le service métier.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'code',
        'address',
        'city',
        'phone',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_main' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected function code(): Attribute
    {
        return Attribute::make(
            set: function (?string $value): ?string {
                $code = $value === null ? '' : mb_strtoupper(trim($value));

                return $code === '' ? null : $code;
            },
        );
    }
}