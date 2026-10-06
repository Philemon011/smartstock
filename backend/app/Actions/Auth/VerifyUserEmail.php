<?php

namespace App\Actions\Auth;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;

class VerifyUserEmail
{
    /**
     * @return bool true si l'email vient d'être vérifié, false s'il l'était déjà
     */
    public function handle(User $user): bool
    {
        $verified = DB::transaction(function () use ($user): bool {
            $locked = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->hasVerifiedEmail()) {
                return false;
            }

            $locked->markEmailAsVerified();

            $trialEndsAt = now()->addDays((int) config('tenants.trial_days'));

            Tenant::query()
                ->whereIn(
                    'id',
                    $locked->memberships()->owners()->active()->select('tenant_id'),
                )
                ->where('status', TenantStatus::Trial->value)
                ->whereNull('trial_ends_at')
                ->update(['trial_ends_at' => $trialEndsAt]);

            return true;
        });

        if ($verified) {
            event(new Verified($user->fresh()));
        }

        return $verified;
    }
}