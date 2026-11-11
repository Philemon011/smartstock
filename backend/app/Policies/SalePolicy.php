<?php

namespace App\Policies;

use App\Models\Sale;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\Response;

class SalePolicy
{
    private const SELL = 'Seuls le propriétaire, les administrateurs et les vendeurs peuvent enregistrer des ventes.';

    private const COLLECT = 'Seuls le propriétaire, les administrateurs et les vendeurs peuvent encaisser des paiements.';

    private const CANCEL = 'Seuls le propriétaire et les administrateurs peuvent annuler une vente.';

    private const VOID = 'Seuls le propriétaire et les administrateurs peuvent annuler un paiement.';

    public function viewAny(User $user): bool
    {
        return app(CurrentTenant::class)->isSet();
    }

    public function view(User $user, Sale $sale): bool
    {
        return app(CurrentTenant::class)->isSet();
    }

    public function create(User $user): Response
    {
        return $this->allowIf(app(CurrentTenant::class)->role()->canSell(), self::SELL);
    }

    public function collect(User $user, Sale $sale): Response
    {
        return $this->allowIf(app(CurrentTenant::class)->role()->canSell(), self::COLLECT);
    }

    public function collectAny(User $user): Response
    {
        return $this->allowIf(app(CurrentTenant::class)->role()->canSell(), self::COLLECT);
    }

    public function cancel(User $user, Sale $sale): Response
    {
        return $this->allowIf(app(CurrentTenant::class)->role()->canManageStructure(), self::CANCEL);
    }

    public function voidPayment(User $user, Sale $sale): Response
    {
        return $this->allowIf(app(CurrentTenant::class)->role()->canManageStructure(), self::VOID);
    }

    private function allowIf(bool $allowed, string $message): Response
    {
        return $allowed ? Response::allow() : Response::deny($message);
    }
}