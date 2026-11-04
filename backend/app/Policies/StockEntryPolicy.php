<?php

namespace App\Policies;

use App\Models\StockEntry;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\Response;

class StockEntryPolicy
{
    private const DENIED = 'Seuls le propriétaire, les administrateurs et les gestionnaires de stock peuvent gérer les entrées de stock.';

    private const DENIED_CANCEL = 'Seuls le propriétaire et les administrateurs peuvent annuler une entrée validée.';

    public function viewAny(User $user): Response
    {
        return $this->manage();
    }

    public function view(User $user, StockEntry $entry): Response
    {
        return $this->manage();
    }

    public function create(User $user): Response
    {
        return $this->manage();
    }

    public function update(User $user, StockEntry $entry): Response
    {
        return $this->manage();
    }

    public function delete(User $user, StockEntry $entry): Response
    {
        return $this->manage();
    }

    public function validate(User $user, StockEntry $entry): Response
    {
        return $this->manage();
    }

    public function cancel(User $user, StockEntry $entry): Response
    {
        return app(CurrentTenant::class)->role()->canManageStructure()
            ? Response::allow()
            : Response::deny(self::DENIED_CANCEL);
    }

    private function manage(): Response
    {
        return app(CurrentTenant::class)->role()->canManageStock()
            ? Response::allow()
            : Response::deny(self::DENIED);
    }
}