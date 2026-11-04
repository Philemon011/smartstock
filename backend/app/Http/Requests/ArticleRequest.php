<?php

namespace App\Http\Requests;

use App\Models\Article;
use App\Models\ArticlePackaging;
use App\Models\Category;
use App\Models\Unit;
use App\Support\Money;
use App\Support\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ArticleRequest extends FormRequest
{
    private ?Unit $unit = null;

    private bool $unitResolved = false;

    public function authorize(): bool
    {
        $article = $this->route('article');

        if ($article instanceof Article) {
            Gate::authorize('update', $article);
        } else {
            Gate::authorize('create', Article::class);
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['name', 'description', 'barcode', 'category_id', 'unit_id'] as $field) {
            if ($this->has($field)) {
                $normalized[$field] = $this->text($this->input($field), collapse: $field === 'name');
            }
        }

        foreach (['sale_price', 'min_stock'] as $field) {
            if ($this->has($field)) {
                $normalized[$field] = $this->number($this->input($field));
            }
        }

        if ($this->has('barcode') && is_string($normalized['barcode'])) {
            $normalized['barcode'] = mb_strtoupper($normalized['barcode']);
        }

        if ($this->has('packagings') && is_array($this->input('packagings'))) {
            $normalized['packagings'] = array_map(function (mixed $item): mixed {
                if (! is_array($item)) {
                    return $item;
                }

                return [
                    'id' => $this->text($item['id'] ?? null, collapse: false),
                    'name' => $this->text($item['name'] ?? null, collapse: true),
                    'quantity' => $this->number($item['quantity'] ?? null),
                ];
            }, $this->input('packagings'));
        }

        $this->merge($normalized);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'bail',
                'required',
                'string',
                'min:2',
                'max:150',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $other = Article::query()
                        ->where('name_key', Article::keyFor($value))
                        ->when($this->currentArticle(), fn ($query, Article $article) => $query->whereKeyNot($article->getKey()))
                        ->first(['code']);

                    if ($other !== null) {
                        $fail("Un article nommé « {$value} » existe déjà ({$other->code}). Précisez la marque ou le format pour les distinguer.");
                    }
                },
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'barcode' => [
                'bail',
                'nullable',
                'string',
                'regex:/^[A-Z0-9._-]{4,32}$/',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === null) {
                        return;
                    }

                    $other = Article::query()
                        ->where('barcode', $value)
                        ->when($this->currentArticle(), fn ($query, Article $article) => $query->whereKeyNot($article->getKey()))
                        ->first(['code', 'name']);

                    if ($other !== null) {
                        $fail("Ce code-barres est déjà utilisé par « {$other->name} » ({$other->code}).");
                    }
                },
            ],
            'category_id' => [
                'bail',
                'nullable',
                'string',
                'size:26',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === null) {
                        return;
                    }

                    $category = Category::query()->find($value);

                    if ($category === null) {
                        $fail('Cette catégorie est introuvable.');
                    } elseif (! $category->is_active && $value !== $this->currentArticle()?->category_id) {
                        $fail('Cette catégorie est désactivée. Choisissez-en une autre.');
                    }
                },
            ],
            'unit_id' => [
                'bail',
                'required',
                'string',
                'size:26',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $unit = Unit::query()->find($value);

                    if ($unit === null) {
                        $fail('Cette unité est introuvable.');
                    } elseif (! $unit->is_active && $value !== $this->currentArticle()?->unit_id) {
                        $fail('Cette unité est désactivée. Choisissez-en une autre.');
                    }
                },
            ],
            'sale_price' => ['bail', 'required', $this->amountRule()],
            'min_stock' => ['bail', 'nullable', $this->quantityRule('Le seuil minimum', positive: false)],
            'track_expiry' => ['nullable', 'boolean'],
            'expiry_alert_days' => ['nullable', 'integer', 'between:1,365'],
            'packagings' => ['nullable', 'array', 'max:10'],
            'packagings.*.id' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value !== null && ! $this->currentArticle()?->packagings()->whereKey($value)->exists()) {
                        $fail('Ce conditionnement est introuvable.');
                    }
                },
            ],
            'packagings.*.name' => [
                'bail',
                'required',
                'string',
                'max:50',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $same = collect($this->input('packagings', []))
                        ->pluck('name')
                        ->filter(fn ($name) => is_string($name) && ArticlePackaging::keyFor($name) === ArticlePackaging::keyFor($value))
                        ->count();

                    if ($same > 1) {
                        $fail('Deux conditionnements portent le même nom.');
                    }
                },
            ],
            'packagings.*.quantity' => [
                'bail',
                'required',
                $this->quantityRule('La quantité du conditionnement', positive: true),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => "Indiquez le nom de l'article.",
            'name.string' => "Le nom de l'article n'est pas valide.",
            'name.min' => "Le nom de l'article doit contenir au moins 2 caractères.",
            'name.max' => "Le nom de l'article ne peut pas dépasser 150 caractères.",
            'description.string' => "La description n'est pas valide.",
            'description.max' => 'La description ne peut pas dépasser 500 caractères.',
            'barcode.string' => "Le code-barres n'est pas valide.",
            'barcode.regex' => 'Le code-barres doit contenir de 4 à 32 lettres, chiffres, points ou tirets.',
            'category_id.string' => "La catégorie n'est pas valide.",
            'category_id.size' => "La catégorie n'est pas valide.",
            'unit_id.required' => "Choisissez l'unité de l'article.",
            'unit_id.string' => "L'unité n'est pas valide.",
            'unit_id.size' => "L'unité n'est pas valide.",
            'sale_price.required' => 'Indiquez le prix de vente.',
            'track_expiry.boolean' => "Le choix sur l'expiration n'est pas valide.",
            'expiry_alert_days.integer' => "Le délai d'alerte doit être un nombre de jours.",
            'expiry_alert_days.between' => "Le délai d'alerte doit être compris entre 1 et 365 jours.",
            'packagings.array' => 'Les conditionnements ne sont pas valides.',
            'packagings.max' => 'Un article ne peut pas avoir plus de 10 conditionnements.',
            'packagings.*.name.required' => 'Indiquez le nom du conditionnement, par exemple Sac.',
            'packagings.*.name.string' => "Le nom du conditionnement n'est pas valide.",
            'packagings.*.name.max' => 'Le nom du conditionnement ne peut pas dépasser 50 caractères.',
            'packagings.*.quantity.required' => 'Indiquez la quantité du conditionnement.',
        ];
    }

    private function amountRule(): Closure
    {
        $currency = app(CurrentTenant::class)->tenant()->currency;
        $decimals = Money::decimals($currency);

        return function (string $attribute, mixed $value, Closure $fail) use ($currency, $decimals): void {
            if (! is_string($value) || ! preg_match('/^\d{1,12}(\.\d+)?$/', $value)) {
                $fail('Le prix de vente doit être un montant valide, par exemple 500.');

                return;
            }

            $fraction = rtrim(explode('.', $value)[1] ?? '', '0');

            if (strlen($fraction) > $decimals) {
                $fail($decimals === 0
                    ? "Le prix de vente ne peut pas avoir de décimales en {$currency}."
                    : "Le prix de vente ne peut pas avoir plus de {$decimals} décimale(s) en {$currency}.");
            }
        };
    }

    private function quantityRule(string $label, bool $positive): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($label, $positive): void {
            if ($value === null) {
                return;
            }

            if (! is_string($value) || ! preg_match('/^\d{1,12}(\.\d{1,3})?$/', $value)) {
                $fail("{$label} doit être un nombre valide, avec 3 décimales au maximum.");

                return;
            }

            $unit = $this->effectiveUnit();

            if ($unit !== null && ! $unit->allows_decimals && rtrim(explode('.', $value)[1] ?? '', '0') !== '') {
                $fail("L'unité « {$unit->name} » n'accepte pas les décimales : saisissez un nombre entier.");

                return;
            }

            if ($positive && bccomp($value, '0', 3) <= 0) {
                $fail("{$label} doit être supérieure à zéro.");
            }
        };
    }

    private function currentArticle(): ?Article
    {
        $article = $this->route('article');

        return $article instanceof Article ? $article : null;
    }

    /**
     * L'unité visée : celle de la saisie, sinon celle que l'article a déjà.
     */
    private function effectiveUnit(): ?Unit
    {
        if (! $this->unitResolved) {
            $id = $this->has('unit_id') ? $this->input('unit_id') : $this->currentArticle()?->unit_id;

            $this->unit = is_string($id) ? Unit::query()->find($id) : null;
            $this->unitResolved = true;
        }

        return $this->unit;
    }

    private function text(mixed $value, bool $collapse): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $clean = $collapse ? preg_replace('/\s+/u', ' ', trim($value)) : trim($value);

        return $clean === '' ? null : $clean;
    }

    private function number(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        $number = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($value));

        return $number === '' ? null : $number;
    }
}