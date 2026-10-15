<?php

namespace App\Models;

use App\Enums\TenantStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasUlids;

    /**
     * Le statut et la fin d'essai sont volontairement absents :
     * ils ne changent que par les services métier.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'country',
        'currency',
        'timezone',
        'phone',
        'address',
        'tax_id',
        'logo_path',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'trial',
        'timezone' => 'UTC',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'trial_ends_at' => 'datetime',
        ];
    }

    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => preg_replace('/\s+/u', ' ', trim($value)),
        );
    }

    protected function country(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => mb_strtoupper(trim($value)),
        );
    }

    protected function currency(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => mb_strtoupper(trim($value)),
        );
    }

    /**
     * Personnes appartenant à l'entreprise.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(TenantUser::class)
            ->withPivot(['role', 'status'])
            ->withTimestamps();
    }

    /**
     * Appartenances (une ligne par personne).
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    public function isOnTrial(): bool
    {
        return $this->status === TenantStatus::Trial;
    }

    /**
     * Jours d'essai restants (jour entamé = jour compté), ou null si l'essai n'a pas démarré.
     */
    public function trialDaysRemaining(): ?int
    {
        if ($this->trial_ends_at === null) {
            return null;
        }

        $seconds = now()->diffInSeconds($this->trial_ends_at, false);

        return max(0, (int) ceil($seconds / 86400));
    }

        /**
     * Les écritures sont permises : abonnement actif, ou essai en cours (la date fait foi, pas besoin de tâche planifiée).
     */
    public function isWritable(): bool
    {
        return match ($this->status) {
            TenantStatus::Active => true,
            TenantStatus::Trial => $this->trial_ends_at === null || $this->trial_ends_at->isFuture(),
            default => false,
        };
    }


        /**
     * Message à montrer quand les écritures sont bloquées (null si l'espace est modifiable).
     */
    public function readOnlyMessage(): ?string
    {
        if ($this->isWritable()) {
            return null;
        }

        return $this->status === TenantStatus::Suspended
            ? 'Cet espace est suspendu. Contactez le support pour le réactiver.'
            : 'Votre essai gratuit est terminé. Vos données restent consultables, mais vous ne pouvez plus les modifier.';
    }
}