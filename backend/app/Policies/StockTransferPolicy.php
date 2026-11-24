<?php

namespace App\Policies;

use App\Models\StockTransfer;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\Response;

class StockTransferPolicy
{
    private const MANAGE = 'Seuls le propriétaire, les administrateurs, les gestionnaires de stock et les vendeurs peuvent gérer les transferts.';

    private const CANCEL = 'Seuls le propriétaire et les administrateurs peuvent annuler un transfert validé.';

    public function viewAny(User $user): bool
    {
        return app(CurrentTenant::class)->isSet();
    }

    public function view(User $user, StockTransfer $transfer): bool
    {
        return app(CurrentTenant::class)->isSet();
    }

    public function create(User $user): Response
    {
        return $this->manage();
    }

    public function update(User $user, StockTransfer $transfer): Response
    {
        return $this->manage();
    }

    public function delete(User $user, StockTransfer $transfer): Response
    {
        return $this->manage();
    }

    public function validate(User $user, StockTransfer $transfer): Response
    {
        return $this->manage();
    }

    public function cancel(User $user, StockTransfer $transfer): Response
    {
        return app(CurrentTenant::class)->role()->canManageStructure()
            ? Response::allow()
            : Response::deny(self::CANCEL);
    }

    private function manage(): Response
    {
        return app(CurrentTenant::class)->role()->canTransfer()
            ? Response::allow()
            : Response::deny(self::MANAGE);
    }
}