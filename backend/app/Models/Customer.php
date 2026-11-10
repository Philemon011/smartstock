<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\NormalizesName;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use BelongsToTenant, HasUlids, NormalizesName;

    /**
     * L'activité ne change que par le service métier.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'phone',
        'email',
        'address',
        'city',
        'notes',
        'credit_limit',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'credit_limit' => 'decimal:4',
        ];
    }

    protected function email(): Attribute
    {
        return Attribute::make(
            set: function (?string $value): ?string {
                $email = $value === null ? '' : mb_strtolower(trim($value));

                return $email === '' ? null : $email;
            },
        );
    }
}