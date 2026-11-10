<?php

namespace App\Policies;

use App\Models\StockExit;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\Response;

class StockExitPolicy
{
    private const MANAGE = 'Seuls le propriétaire, les administrateurs et les gestionnaires de stock peuvent gérer les sorties de stock.';

    private const CANCEL = 'Seuls le propriétaire et les administrateurs peuvent annuler une sortie validée.';

    public function viewAny(User $user): Response
    {
        return $this->manage();
    }

    public function view(User $user, StockExit $exit): Response
    {
        return $this->manage();
    }

    public function create(User $user): Response
    {
        return $this->manage();
    }

    public function update(User $user, StockExit $exit): Response
    {
        return $this->manage();
    }

    public function delete(User $user, StockExit $exit): Response
    {
        return $this->manage();
    }

    public function validate(User $user, StockExit $exit): Response
    {
        return $this->manage();
    }

    public function cancel(User $user, StockExit $exit): Response
    {
        return app(CurrentTenant::class)->role()->canManageStructure()
            ? Response::allow()
            : Response::deny(self::CANCEL);
    }

    private function manage(): Response
    {
        return app(CurrentTenant::class)->role()->canManageStock()
            ? Response::allow()
            : Response::deny(self::MANAGE);
    }
}