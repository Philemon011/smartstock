<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Article;
use App\Models\ArticlePackaging;
use App\Models\ArticlePriceChange;
use App\Models\StockEntryLine;
use App\Models\StockExitLine;
use App\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use App\Models\SaleLine;

class ArticleService
{
    public function __construct(private readonly SequenceGenerator $sequences) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Article
    {
        return $this->atomically(function () use ($data): Article {
            $packagings = $data['packagings'] ?? [];
            unset($data['packagings']);

            $article = new Article($data);
            $article->code = sprintf(
                'ART-%06d',
                $this->sequences->next(app(CurrentTenant::class)->id(), 'article'),
            );
            $article->save();

            $this->syncPackagings($article, $packagings);
            $this->recordPrice($article, null, (string) $article->sale_price);

            return $article->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Article $article, array $data): Article
    {
        return $this->atomically(function () use ($article, $data): Article {
            $article->refresh();

            $packagings = array_key_exists('packagings', $data) ? $data['packagings'] : null;
            unset($data['packagings']);

            if (
                array_key_exists('unit_id', $data)
                && $data['unit_id'] !== $article->unit_id
                && $this->hasMovements($article)
            ) {
                throw new BusinessRuleException(
                    "L'unité ne peut plus changer : cet article a déjà du stock ou des mouvements.",
                    'unit_locked',
                );
            }

            $oldPrice = (string) $article->sale_price;

            $article->fill($data)->save();

            if (bccomp($oldPrice, (string) $article->sale_price, 4) !== 0) {
                $this->recordPrice($article, $oldPrice, (string) $article->sale_price);
            }

            if ($packagings !== null) {
                $this->syncPackagings($article, $packagings);
            }

            return $article->refresh();
        });
    }

    public function deactivate(Article $article): Article
    {
        return $this->atomically(function () use ($article): Article {
            $article->refresh();
            $article->forceFill(['is_active' => false])->save();

            return $article;
        });
    }

    public function activate(Article $article): Article
    {
        return $this->atomically(function () use ($article): Article {
            $article->refresh();
            $article->forceFill(['is_active' => true])->save();

            return $article;
        });
    }

    public function delete(Article $article): void
    {
        $imagePath = $article->image_path;

        $this->atomically(function () use ($article): void {
            $article->refresh();

            if ($this->hasMovements($article)) {
                throw new BusinessRuleException(
                    'Cet article a déjà du stock ou des mouvements. Désactivez-le plutôt que de le supprimer.',
                    'article_used',
                );
            }

            $article->delete();
        });

        if ($imagePath !== null) {
            Storage::disk(config('filesystems.files_disk'))->delete($imagePath);
        }
    }

    /**
     * Un article « a des mouvements »  dès qu’une entrée, une sortie ou une vente (même en brouillon) le mentionne.
     */
    private function hasMovements(Article $article): bool
    {
                return StockEntryLine::query()->where('article_id', $article->id)->exists()
            || StockExitLine::query()->where('article_id', $article->id)->exists()
            || SaleLine::query()->where('article_id', $article->id)->exists();
    }



        /**
     * Change le prix de vente en gardant la trace dans l'historique (utilisé aussi par les entrées de stock).
     */
    public function changeSalePrice(Article $article, string $newPrice): void
    {
        $oldPrice = (string) $article->sale_price;

        if (bccomp($oldPrice, $newPrice, 4) === 0) {
            return;
        }

        $article->forceFill(['sale_price' => $newPrice])->save();
        $this->recordPrice($article, $oldPrice, $newPrice);
    }

    private function recordPrice(Article $article, ?string $oldPrice, string $newPrice): void
    {
        (new ArticlePriceChange)->forceFill([
            'tenant_id' => $article->tenant_id,
            'article_id' => $article->id,
            'old_price' => $oldPrice,
            'new_price' => $newPrice,
            'changed_by' => Auth::id(),
        ])->save();
    }

    /**
     * @param  list<array{id?: string|null, name: string, quantity: string}>  $items
     */
    private function syncPackagings(Article $article, array $items): void
    {
        $existing = $article->packagings()->get()->keyBy('id');
        $keep = collect($items)->pluck('id')->filter()->all();

        $article->packagings()->whereNotIn('id', $keep)->delete();

        foreach ($items as $item) {
            $id = $item['id'] ?? null;
            $packaging = $id !== null && $existing->has($id) ? $existing->get($id) : new ArticlePackaging;

            $packaging->fill(['name' => $item['name'], 'quantity' => $item['quantity']]);
            $packaging->forceFill(['tenant_id' => $article->tenant_id, 'article_id' => $article->id])->save();
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function atomically(Closure $callback): mixed
    {
        $tenantId = app(CurrentTenant::class)->id();

        return DB::transaction(function () use ($callback, $tenantId) {
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();

            try {
                return $callback();
            } catch (UniqueConstraintViolationException $exception) {
                $message = $exception->getMessage();

                $errors = match (true) {
                    str_contains($message, 'articles_tenant_barcode_unique') => [
                        'barcode' => 'Ce code-barres est déjà utilisé par un autre article.',
                    ],
                    str_contains($message, 'article_packagings_article_name_unique') => [
                        'packagings' => 'Deux conditionnements portent le même nom.',
                    ],
                    str_contains($message, 'articles_tenant_name_unique') => [
                        'name' => 'Un article portant ce nom existe déjà. Précisez la marque ou le format pour les distinguer.',
                    ],
                    // Toute autre collision (par exemple le code automatique) est une anomalie : on ne la déguise pas.
                    default => null,
                };

                if ($errors === null) {
                    throw $exception;
                }

                throw ValidationException::withMessages($errors);
            }
        });
    }
}
