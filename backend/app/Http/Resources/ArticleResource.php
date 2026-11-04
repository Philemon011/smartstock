<?php

namespace App\Http\Resources;

use App\Models\ArticlePackaging;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

/**
 * @mixin \App\Models\Article
 */
class ArticleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tenant = app(CurrentTenant::class)->tenant();
        $writable = $tenant->isWritable();
        $decimals = (bool) $this->unit->allows_decimals;

        return [
            'id' => $this->id,
            'code' => $this->code,
            'barcode' => $this->barcode,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category
                ? ['id' => $this->category->id, 'name' => $this->category->name]
                : null,
            'unit' => [
                'id' => $this->unit->id,
                'name' => $this->unit->name,
                'symbol' => $this->unit->symbol,
                'allows_decimals' => $decimals,
            ],
            'sale_price' => Money::normalize((string) $this->sale_price, $tenant->currency),
            'min_stock' => Quantity::format((string) $this->min_stock, $decimals),
            'track_expiry' => $this->track_expiry,
            'expiry_alert_days' => $this->expiry_alert_days,
            'image_url' => $this->imageUrl(),
            'packagings' => $this->packagings->map(fn (ArticlePackaging $packaging): array => [
                'id' => $packaging->id,
                'name' => $packaging->name,
                'quantity' => Quantity::format((string) $packaging->quantity, $decimals),
            ])->values(),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'can' => [
                'update' => $writable && Gate::allows('update', $this->resource),
                'delete' => $writable && Gate::allows('delete', $this->resource),
                'toggle' => $writable && Gate::allows('deactivate', $this->resource),
            ],
        ];
    }

    /**
     * Lien signé vers la photo privée. Il est stable pendant environ une heure (le navigateur peut
     * donc le mettre en cache) et change dès que la photo change.
     */
    private function imageUrl(): ?string
    {
        if ($this->image_path === null) {
            return null;
        }

        return URL::temporarySignedRoute(
            'articles.image',
            now()->startOfHour()->addHours(2),
            ['article' => $this->id, 'v' => substr(md5($this->image_path), 0, 8)],
        );
    }
}