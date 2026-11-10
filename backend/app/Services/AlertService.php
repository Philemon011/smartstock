<?php

namespace App\Services;

use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class AlertService
{
    public const TYPES = ['expired', 'expiring', 'low_stock', 'out_of_stock'];

    /**
     * Le jour « d'aujourd'hui » de l'entreprise, selon son fuseau horaire.
     */
    public function today(): string
    {
        return now(app(CurrentTenant::class)->tenant()->timezone)->toDateString();
    }

    /**
     * @return array{expired: int, expiring: int, low_stock: int, out_of_stock: int, total: int}
     */
    public function summary(): array
    {
        $counts = [
            'expired' => $this->expiredLots()->count(),
            'expiring' => $this->expiringLots()->count(),
            'low_stock' => $this->lowStockArticles()->count(),
            'out_of_stock' => $this->outOfStockArticles()->count(),
        ];

        return $counts + ['total' => array_sum($counts)];
    }

    /**
     * Lots encore en stock dont la date d'expiration est passée.
     */
    public function expiredLots(): Builder
    {
        return $this->lotsInStock()->where('l.expiry_date', '<', $this->today());
    }

    /**
     * Lots encore en stock qui expirent dans le délai d'alerte de leur article (aujourd'hui compris).
     */
    public function expiringLots(): Builder
    {
        $today = $this->today();

        return $this->lotsInStock()
            ->where('l.expiry_date', '>=', $today)
            ->whereRaw('l.expiry_date <= (CAST(? AS date) + a.expiry_alert_days)', [$today]);
    }

    /**
     * Articles actifs dont le stock est à zéro, s'ils ont déjà eu du stock ou ont un seuil défini.
     */
    public function outOfStockArticles(): Builder
    {
        return $this->activeArticlesWithStock()
            ->whereRaw('COALESCE(s.total, 0) = 0')
            ->whereRaw('(s.lots_count IS NOT NULL OR a.min_stock > 0)');
    }

    /**
     * Articles actifs dont le stock est positif mais inférieur ou égal à leur seuil.
     */
    public function lowStockArticles(): Builder
    {
        return $this->activeArticlesWithStock()
            ->where('a.min_stock', '>', 0)
            ->whereRaw('COALESCE(s.total, 0) > 0')
            ->whereRaw('COALESCE(s.total, 0) <= a.min_stock');
    }

    private function lotsInStock(): Builder
    {
        return DB::table('stock_lots as l')
            ->join('articles as a', 'a.id', '=', 'l.article_id')
            ->join('warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->join('units as u', 'u.id', '=', 'a.unit_id')
            ->where('l.tenant_id', app(CurrentTenant::class)->id())
            ->where('l.quantity_remaining', '>', 0)
            ->whereNotNull('l.expiry_date');
    }

    private function activeArticlesWithStock(): Builder
    {
        $tenantId = app(CurrentTenant::class)->id();

        $totals = DB::table('stock_lots')
            ->where('tenant_id', $tenantId)
            ->groupBy('article_id')
            ->selectRaw('article_id, SUM(quantity_remaining) AS total, COUNT(*) AS lots_count');

        return DB::table('articles as a')
            ->join('units as u', 'u.id', '=', 'a.unit_id')
            ->leftJoinSub($totals, 's', 's.article_id', '=', 'a.id')
            ->where('a.tenant_id', $tenantId)
            ->where('a.is_active', true);
    }
}