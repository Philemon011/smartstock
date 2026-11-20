<?php

namespace App\Http\Resources;

use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * @mixin \App\Models\Tenant
 */
class CompanyProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $current = app(CurrentTenant::class);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'country' => $this->country,
            'currency' => $this->currency,
            'timezone' => $this->timezone,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'city' => $this->city,
            'website' => $this->website,
            'tax_id' => $this->tax_id,
            'registration_number' => $this->registration_number,
            'legal_mentions' => $this->legal_mentions,
            'receipt_footer' => $this->receipt_footer,
            'logo_url' => $this->logoUrl(),
            'can' => [
                'update' => $current->tenant()->isWritable() && $current->role()->canManageStructure(),
            ],
        ];
    }

    /**
     * Lien signé et daté : la balise <img> n'envoie pas d'en-tête personnalisé, la signature contrôle l'accès.
     * Le paramètre « v » change à chaque nouveau logo, pour ne jamais afficher l'ancien.
     */
    private function logoUrl(): ?string
    {
        if ($this->logo_path === null) {
            return null;
        }

        return URL::temporarySignedRoute('tenants.logo', now()->addHours(6), [
            'tenant' => $this->id,
            'v' => substr(md5((string) $this->logo_path), 0, 10),
        ]);
    }
}