<?php

namespace App\Http\Resources;

use App\Models\TenantUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\User
 */
class MeResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'user' => [
                'id' => $this->id,
                'name' => $this->name,
                'email' => $this->email,
                'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            ],
            'memberships' => $this->memberships->map(fn (TenantUser $membership): array => [
                'role' => $membership->role->value,
                'role_label' => $membership->role->label(),
                'status' => $membership->status->value,
                'tenant' => [
                    'id' => $membership->tenant->id,
                    'name' => $membership->tenant->name,
                    'country' => $membership->tenant->country,
                    'currency' => $membership->tenant->currency,
                    'timezone' => $membership->tenant->timezone,
                    'status' => $membership->tenant->status->value,
                    'trial_ends_at' => $membership->tenant->trial_ends_at?->toIso8601String(),
                    'trial_days_remaining' => $membership->tenant->trialDaysRemaining(),
                    'read_only_message' => $membership->tenant->readOnlyMessage(),
                ],
            ])->values(),
        ];
    }
}