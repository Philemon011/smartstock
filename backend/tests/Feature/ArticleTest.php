<?php

namespace Tests\Feature;

use App\Enums\TenantRole;
use App\Enums\TenantStatus;
use App\Models\Article;
use App\Models\Category;
use App\Models\Tenant;
use App\Models\Unit;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;
use App\Services\SequenceGenerator;

class ArticleTest extends TestCase
{
    use InteractsWithTenants;

    private const URL = '/api/v1/articles';

    private function unit(Tenant $tenant, string $symbol): Unit
    {
        return Unit::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('symbol_key', Unit::symbolKey($symbol))
            ->firstOrFail();
    }

    private function category(Tenant $tenant, string $name, ?string $parentId = null, bool $active = true): Category
    {
        $category = (new Category)->forceFill([
            'tenant_id' => $tenant->id,
            'parent_id' => $parentId,
            'name' => $name,
            'is_active' => $active,
        ]);
        $category->save();

        return $category;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Tenant $tenant, array $overrides = []): array
    {
        return array_merge([
            'name' => 'Riz local',
            'unit_id' => $this->unit($tenant, 'sac')->id,
            'sale_price' => '500',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeArticle(Tenant $tenant, string $name, array $attributes = []): Article
    {
        $number = app(SequenceGenerator::class)->next($tenant->id, 'article');

        $article = (new Article)->forceFill(array_merge([
            'tenant_id' => $tenant->id,
            'code' => sprintf('ART-%06d', $number),
            'name' => $name,
            'unit_id' => $this->unit($tenant, 'sac')->id,
            'sale_price' => '500',
        ], $attributes));
        $article->save();

        return $article;
    }

    // ----------------------------------------------------------------- Devises

    public function test_every_supported_currency_has_a_decimals_setting(): void
    {
        foreach (array_keys(config('tenants.currencies')) as $currency) {
            $this->assertArrayHasKey($currency, config('tenants.currency_decimals'), "Devise sans réglage : {$currency}");
        }
    }

    // ------------------------------------------------------------------ Codes

    public function test_codes_are_generated_sequentially_per_company_and_cannot_be_chosen(): void
    {
        [$tenantA, $ownerA] = $this->createTenant('a');
        [$tenantB, $ownerB] = $this->createTenant('b');

        $this->asMember($ownerA, $tenantA);
        $first = $this->postJson(self::URL, $this->payload($tenantA, ['name' => 'Riz', 'code' => 'ART-999999']))
            ->assertCreated()
            ->assertJsonPath('data.code', 'ART-000001');
        $this->postJson(self::URL, $this->payload($tenantA, ['name' => 'Huile']))->assertJsonPath('data.code', 'ART-000002');
        $this->postJson(self::URL, $this->payload($tenantA, ['name' => 'Sucre']))->assertJsonPath('data.code', 'ART-000003');

        $this->asMember($ownerB, $tenantB);
        $this->postJson(self::URL, $this->payload($tenantB, ['name' => 'Riz']))->assertJsonPath('data.code', 'ART-000001');

        $this->assertNotNull($first->json('data.id'));
    }

    // ------------------------------------------------------ Isolation et droits

    public function test_each_company_only_sees_and_touches_its_own_articles(): void
    {
        [$tenantA, $ownerA] = $this->createTenant('a');
        [$tenantB] = $this->createTenant('b');
        $foreign = $this->makeArticle($tenantB, 'Riz local');

        $this->asMember($ownerA, $tenantA);

        $this->getJson(self::URL)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::URL."/{$foreign->id}")->assertNotFound();
        $this->putJson(self::URL."/{$foreign->id}", $this->payload($tenantA, ['name' => 'Piraté']))->assertNotFound();
        $this->deleteJson(self::URL."/{$foreign->id}")->assertNotFound();
        $this->getJson(self::URL."/{$foreign->id}/price-history")->assertNotFound();

        $this->assertSame('Riz local', $foreign->fresh()->name);
    }

    public function test_catalog_managers_can_write_and_other_roles_cannot(): void
    {
        [$tenant] = $this->createTenant();
        $article = $this->makeArticle($tenant, 'Riz local');
        $denied = 'Seuls le propriétaire, les administrateurs et les gestionnaires de stock peuvent gérer les articles.';

        foreach ([TenantRole::Admin, TenantRole::StockManager] as $role) {
            $this->asMember($this->addMember($tenant, $role), $tenant);
            $this->postJson(self::URL, $this->payload($tenant, ['name' => 'Article '.$role->value]))->assertCreated();
        }

        foreach ([TenantRole::Seller, TenantRole::Viewer] as $role) {
            $this->asMember($this->addMember($tenant, $role), $tenant);

            $this->getJson(self::URL)->assertOk()->assertJsonPath('meta.can.create', false);
            $this->postJson(self::URL, $this->payload($tenant, ['name' => 'Interdit']))
                ->assertForbidden()
                ->assertJsonPath('message', $denied);
            $this->putJson(self::URL."/{$article->id}", $this->payload($tenant))->assertForbidden();
            $this->deleteJson(self::URL."/{$article->id}")->assertForbidden();
            $this->postJson(self::URL."/{$article->id}/deactivate")->assertForbidden();
        }
    }

    // ---------------------------------------------------------------- Création

    public function test_create_a_complete_article(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $category = $this->category($tenant, 'Alimentation');
        $this->asMember($owner, $tenant);

        $response = $this->postJson(self::URL, $this->payload($tenant, [
            'name' => '  Riz   parfumé  ',
            'description' => ' Sac de 25 kg ',
            'barcode' => ' 6001234567890 ',
            'category_id' => $category->id,
            'sale_price' => '12 500',
            'min_stock' => '10',
            'track_expiry' => true,
            'expiry_alert_days' => 45,
            'packagings' => [['name' => ' Demi sac ', 'quantity' => '2']],
        ]))
            ->assertCreated()
            ->assertJsonPath('data.name', 'Riz parfumé')
            ->assertJsonPath('data.description', 'Sac de 25 kg')
            ->assertJsonPath('data.barcode', '6001234567890')
            ->assertJsonPath('data.category.name', 'Alimentation')
            ->assertJsonPath('data.unit.symbol', 'sac')
            ->assertJsonPath('data.sale_price', '12500')
            ->assertJsonPath('data.min_stock', '10')
            ->assertJsonPath('data.track_expiry', true)
            ->assertJsonPath('data.expiry_alert_days', 45)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.image_url', null)
            ->assertJsonPath('data.packagings.0.name', 'Demi sac')
            ->assertJsonPath('data.packagings.0.quantity', '2')
            ->assertJsonPath('data.can.update', true);

        $this->getJson(self::URL.'/'.$response->json('data.id').'/price-history')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.old_price', null)
            ->assertJsonPath('data.0.new_price', '12500');
    }

    public function test_defaults_apply_when_optional_fields_are_missing(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $this->postJson(self::URL, $this->payload($tenant))
            ->assertCreated()
            ->assertJsonPath('data.track_expiry', false)
            ->assertJsonPath('data.expiry_alert_days', 30)
            ->assertJsonPath('data.min_stock', '0')
            ->assertJsonPath('data.category', null)
            ->assertJsonPath('data.packagings', []);
    }

    public function test_validation_messages_are_explicit(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $this->postJson(self::URL, [])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', "Indiquez le nom de l'article.")
            ->assertJsonPath('errors.unit_id.0', "Choisissez l'unité de l'article.")
            ->assertJsonPath('errors.sale_price.0', 'Indiquez le prix de vente.');

        $this->postJson(self::URL, $this->payload($tenant, ['barcode' => 'ab', 'expiry_alert_days' => 400]))
            ->assertStatus(422)
            ->assertJsonPath('errors.barcode.0', 'Le code-barres doit contenir de 4 à 32 lettres, chiffres, points ou tirets.')
            ->assertJsonPath('errors.expiry_alert_days.0', "Le délai d'alerte doit être compris entre 1 et 365 jours.");
    }

    // ------------------------------------------------------------ Montants

    public function test_prices_follow_the_decimals_of_the_company_currency(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $this->postJson(self::URL, $this->payload($tenant, ['sale_price' => '500.5']))
            ->assertStatus(422)
            ->assertJsonPath('errors.sale_price.0', 'Le prix de vente ne peut pas avoir de décimales en XOF.');

        $this->postJson(self::URL, $this->payload($tenant, ['sale_price' => '500.00']))->assertCreated();
        $this->postJson(self::URL, $this->payload($tenant, ['name' => 'Autre', 'sale_price' => 'abc']))
            ->assertStatus(422)
            ->assertJsonPath('errors.sale_price.0', 'Le prix de vente doit être un montant valide, par exemple 500.');

        $tenant->forceFill(['currency' => 'EUR'])->save();

        $this->postJson(self::URL, $this->payload($tenant, ['name' => 'Eurotest', 'sale_price' => '12,50']))
            ->assertCreated()
            ->assertJsonPath('data.sale_price', '12.50');

        $this->postJson(self::URL, $this->payload($tenant, ['name' => 'Eurotest 2', 'sale_price' => '12.505']))
            ->assertStatus(422)
            ->assertJsonPath('errors.sale_price.0', 'Le prix de vente ne peut pas avoir plus de 2 décimale(s) en EUR.');
    }

    // ------------------------------------------------------------- Quantités

    public function test_quantities_follow_the_decimals_rule_of_the_unit(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $this->postJson(self::URL, $this->payload($tenant, ['min_stock' => '2.5']))
            ->assertStatus(422)
            ->assertJsonPath('errors.min_stock.0', "L'unité « Sac » n'accepte pas les décimales : saisissez un nombre entier.");

        $this->postJson(self::URL, $this->payload($tenant, [
            'name' => 'Riz au kilo',
            'unit_id' => $this->unit($tenant, 'kg')->id,
            'min_stock' => '2.5',
            'packagings' => [['name' => 'Sac', 'quantity' => '25']],
        ]))
            ->assertCreated()
            ->assertJsonPath('data.min_stock', '2.5')
            ->assertJsonPath('data.packagings.0.quantity', '25');

        $this->postJson(self::URL, $this->payload($tenant, [
            'name' => 'Huile en bidons',
            'unit_id' => $this->unit($tenant, 'bidon')->id,
            'packagings' => [['name' => 'Demi', 'quantity' => '0.5']],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'packagings.0.quantity' => "L'unité « Bidon » n'accepte pas les décimales : saisissez un nombre entier.",
            ]);

        $this->postJson(self::URL, $this->payload($tenant, [
            'name' => 'Sucre',
            'unit_id' => $this->unit($tenant, 'kg')->id,
            'packagings' => [['name' => 'Zéro', 'quantity' => '0']],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'packagings.0.quantity' => 'La quantité du conditionnement doit être supérieure à zéro.',
            ]);
    }

    // ------------------------------------------------------------ Unicité

    public function test_names_and_barcodes_are_unique_with_helpful_messages(): void
    {
        [$tenantA, $ownerA] = $this->createTenant('a');
        [$tenantB, $ownerB] = $this->createTenant('b');
        $existing = $this->makeArticle($tenantA, 'Riz local', ['barcode' => '6001234567890']);

        $this->asMember($ownerA, $tenantA);

        $this->postJson(self::URL, $this->payload($tenantA, ['name' => ' riz   LOCAL ']))
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.name.0',
                'Un article nommé « riz LOCAL » existe déjà (ART-000001). Précisez la marque ou le format pour les distinguer.',
            );

        $this->postJson(self::URL, $this->payload($tenantA, ['name' => 'Autre riz', 'barcode' => '6001234567890']))
            ->assertStatus(422)
            ->assertJsonPath('errors.barcode.0', 'Ce code-barres est déjà utilisé par « Riz local » (ART-000001).');

        $this->putJson(self::URL."/{$existing->id}", $this->payload($tenantA, ['barcode' => '6001234567890']))->assertOk();

        $this->asMember($ownerB, $tenantB);
        $this->postJson(self::URL, $this->payload($tenantB, ['barcode' => '6001234567890']))->assertCreated();
    }

    // ---------------------------------------------- Catégorie et unité valides

    public function test_category_and_unit_must_exist_and_be_active(): void
    {
        [$tenantA, $ownerA] = $this->createTenant('a');
        [$tenantB] = $this->createTenant('b');
        $inactiveCategory = $this->category($tenantA, 'Archive', null, false);
        $foreignCategory = $this->category($tenantB, 'Autre entreprise');
        $inactiveUnit = $this->unit($tenantA, 'palette');
        $inactiveUnit->forceFill(['is_active' => false])->save();

        $this->asMember($ownerA, $tenantA);

        $this->postJson(self::URL, $this->payload($tenantA, ['category_id' => $inactiveCategory->id]))
            ->assertStatus(422)
            ->assertJsonPath('errors.category_id.0', 'Cette catégorie est désactivée. Choisissez-en une autre.');

        $this->postJson(self::URL, $this->payload($tenantA, ['category_id' => $foreignCategory->id]))
            ->assertStatus(422)
            ->assertJsonPath('errors.category_id.0', 'Cette catégorie est introuvable.');

        $this->postJson(self::URL, $this->payload($tenantA, ['unit_id' => $inactiveUnit->id]))
            ->assertStatus(422)
            ->assertJsonPath('errors.unit_id.0', 'Cette unité est désactivée. Choisissez-en une autre.');

        $this->postJson(self::URL, $this->payload($tenantA, ['unit_id' => $this->unit($tenantB, 'kg')->id]))
            ->assertStatus(422)
            ->assertJsonPath('errors.unit_id.0', 'Cette unité est introuvable.');
    }

    // ------------------------------------------------------- Prix et historique

    public function test_price_changes_are_recorded_in_the_history(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $id = $this->postJson(self::URL, $this->payload($tenant, ['sale_price' => '300']))->json('data.id');

        $this->putJson(self::URL."/{$id}", $this->payload($tenant, ['sale_price' => '400']))
            ->assertOk()
            ->assertJsonPath('data.sale_price', '400');

        $this->putJson(self::URL."/{$id}", $this->payload($tenant, ['sale_price' => '400', 'description' => 'Sans changement de prix']))
            ->assertOk();

        $this->putJson(self::URL."/{$id}", $this->payload($tenant, ['sale_price' => '350']))->assertOk();

        $history = $this->getJson(self::URL."/{$id}/price-history")->assertOk();

        $this->assertSame(['350', '400', '300'], $history->json('data.*.new_price'));
        $this->assertSame(['400', '300', null], $history->json('data.*.old_price'));
        $this->assertSame($owner->name, $history->json('data.0.changed_by'));
    }

    // ---------------------------------------------------------- Conditionnements

    public function test_packagings_can_be_added_changed_and_removed(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $created = $this->postJson(self::URL, $this->payload($tenant, [
            'unit_id' => $this->unit($tenant, 'kg')->id,
            'packagings' => [
                ['name' => 'Sac', 'quantity' => '25'],
                ['name' => 'Demi-sac', 'quantity' => '12.5'],
            ],
        ]))->assertCreated();

        $sacId = collect($created->json('data.packagings'))->firstWhere('name', 'Sac')['id'];

        $this->putJson(self::URL.'/'.$created->json('data.id'), $this->payload($tenant, [
            'unit_id' => $this->unit($tenant, 'kg')->id,
            'packagings' => [
                ['id' => $sacId, 'name' => 'Sac de riz', 'quantity' => '50'],
                ['name' => 'Carton', 'quantity' => '10'],
            ],
        ]))
            ->assertOk()
            ->assertJsonCount(2, 'data.packagings');

        $names = $this->getJson(self::URL.'/'.$created->json('data.id'))->json('data.packagings.*.name');
        sort($names);

        $this->assertSame(['Carton', 'Sac de riz'], $names);

        $this->putJson(self::URL.'/'.$created->json('data.id'), $this->payload($tenant, [
            'unit_id' => $this->unit($tenant, 'kg')->id,
            'packagings' => [
                ['name' => 'Sac', 'quantity' => '25'],
                ['name' => ' sac ', 'quantity' => '30'],
            ],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['packagings.0.name' => 'Deux conditionnements portent le même nom.']);
    }

    // ---------------------------------------------------- Liste, filtres, pages

    public function test_the_list_supports_search_category_status_and_pagination(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $boissons = $this->category($tenant, 'Boissons');
        $sodas = $this->category($tenant, 'Sodas', $boissons->id);
        $alimentation = $this->category($tenant, 'Alimentation');

        $this->makeArticle($tenant, 'Riz local', ['category_id' => $alimentation->id, 'barcode' => '6001111111111']);
        $this->makeArticle($tenant, 'Cola 33cl', ['category_id' => $sodas->id]);
        $inactive = $this->makeArticle($tenant, 'Eau minérale', ['category_id' => $boissons->id]);
        $inactive->forceFill(['is_active' => false])->save();

        $this->asMember($owner, $tenant);

        $this->assertSame(['Riz local'], $this->getJson(self::URL.'?search=RIZ')->json('data.*.name'));
        $this->assertSame(['Cola 33cl'], $this->getJson(self::URL.'?search=ART-000002')->json('data.*.name'));
        $this->assertSame(['Riz local'], $this->getJson(self::URL.'?search=6001111')->json('data.*.name'));
        $this->assertSame(['Cola 33cl', 'Eau minérale'], $this->getJson(self::URL.'?category_id='.$boissons->id)->json('data.*.name'));
        $this->assertSame(['Eau minérale'], $this->getJson(self::URL.'?status=inactive')->json('data.*.name'));

        for ($i = 1; $i <= 25; $i++) {
            $this->makeArticle($tenant, sprintf('Produit %02d', $i));
        }

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 28)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.can.create', true);
    }

    // ------------------------------------------- Activation, suppression, usages

    public function test_an_article_can_be_deactivated_reactivated_and_deleted(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $id = $this->makeArticle($tenant, 'Riz local')->id;

        $this->asMember($owner, $tenant);

        $this->postJson(self::URL."/{$id}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);
        $this->postJson(self::URL."/{$id}/activate")->assertOk()->assertJsonPath('data.is_active', true);
        $this->deleteJson(self::URL."/{$id}")->assertNoContent();

        $this->assertDatabaseMissing('articles', ['id' => $id]);
    }

    public function test_units_and_categories_used_by_articles_are_protected(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $category = $this->category($tenant, 'Alimentation');
        $sac = $this->unit($tenant, 'sac');
        $this->makeArticle($tenant, 'Riz local', ['category_id' => $category->id]);

        $this->asMember($owner, $tenant);

        $this->deleteJson("/api/v1/units/{$sac->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'unit_used');

        $this->putJson("/api/v1/units/{$sac->id}", ['name' => 'Sac', 'symbol' => 'sac', 'allows_decimals' => true])
            ->assertStatus(409)
            ->assertJsonPath('code', 'unit_used');

        $this->deleteJson("/api/v1/categories/{$category->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'category_used');

        $this->postJson("/api/v1/units/{$sac->id}/deactivate")->assertOk();
    }

    // -------------------------------------------------------------------- Photo

    public function test_the_photo_can_be_uploaded_replaced_served_and_removed(): void
    {
        Storage::fake(config('filesystems.files_disk'));

        [$tenant, $owner] = $this->createTenant();
        $id = $this->makeArticle($tenant, 'Riz local')->id;

        $this->asMember($owner, $tenant);

        $first = $this->post(self::URL."/{$id}/image", [
            'image' => UploadedFile::fake()->image('riz.jpg', 300, 300)->size(500),
        ], ['Accept' => 'application/json'])->assertOk();

        $url = $first->json('data.image_url');
        $this->assertNotNull($url);

        $firstPath = Article::withoutGlobalScopes()->find($id)->image_path;
        Storage::disk(config('filesystems.files_disk'))->assertExists($firstPath);

        $this->get($url)->assertOk();
        $this->get($url.'x')->assertForbidden();

        $this->post(self::URL."/{$id}/image", [
            'image' => UploadedFile::fake()->image('riz2.png', 300, 300)->size(400),
        ], ['Accept' => 'application/json'])->assertOk();

        $secondPath = Article::withoutGlobalScopes()->find($id)->image_path;
        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk(config('filesystems.files_disk'))->assertMissing($firstPath);
        Storage::disk(config('filesystems.files_disk'))->assertExists($secondPath);

        $this->deleteJson(self::URL."/{$id}/image")->assertNoContent();
        Storage::disk(config('filesystems.files_disk'))->assertMissing($secondPath);
        $this->assertNull(Article::withoutGlobalScopes()->find($id)->image_path);
    }

    public function test_the_photo_must_be_a_small_image(): void
    {
        Storage::fake(config('filesystems.files_disk'));

        [$tenant, $owner] = $this->createTenant();
        $id = $this->makeArticle($tenant, 'Riz local')->id;

        $this->asMember($owner, $tenant);

        $this->post(self::URL."/{$id}/image", [
            'image' => UploadedFile::fake()->image('gros.jpg', 300, 300)->size(3000),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('errors.image.0', 'La photo ne peut pas dépasser 2 Mo.');

        $this->post(self::URL."/{$id}/image", [
            'image' => UploadedFile::fake()->create('bon.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('errors.image.0', 'La photo doit être au format JPG, PNG ou WebP.');

        $this->post(self::URL."/{$id}/image", [], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('errors.image.0', 'Choisissez une photo.');
    }

    public function test_deleting_an_article_removes_its_photo(): void
    {
        Storage::fake(config('filesystems.files_disk'));

        [$tenant, $owner] = $this->createTenant();
        $id = $this->makeArticle($tenant, 'Riz local')->id;

        $this->asMember($owner, $tenant);

        $this->post(self::URL."/{$id}/image", [
            'image' => UploadedFile::fake()->image('riz.jpg', 300, 300)->size(300),
        ], ['Accept' => 'application/json'])->assertOk();

        $path = Article::withoutGlobalScopes()->find($id)->image_path;

        $this->deleteJson(self::URL."/{$id}")->assertNoContent();

        Storage::disk(config('filesystems.files_disk'))->assertMissing($path);
    }

    // ----------------------------------------------------------- Lecture seule

    public function test_an_expired_company_can_read_but_not_write(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->makeArticle($tenant, 'Riz local');
        $tenant->forceFill(['status' => TenantStatus::Expired])->save();

        $this->asMember($owner, $tenant);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('meta.can.create', false)
            ->assertJsonPath('data.0.can.update', false);

        $this->postJson(self::URL, $this->payload($tenant, ['name' => 'Interdit']))
            ->assertForbidden()
            ->assertJsonPath('code', 'tenant_read_only');
    }

    // ------------------------------------------------------ Garde-fous de la base

    public function test_the_database_refuses_a_unit_from_another_company(): void
    {
        [$tenantA] = $this->createTenant('a');
        [$tenantB] = $this->createTenant('b');

        $this->expectException(QueryException::class);

        $this->makeArticle($tenantA, 'Riz local', ['unit_id' => $this->unit($tenantB, 'kg')->id]);
    }

    public function test_the_database_refuses_a_category_from_another_company(): void
    {
        [$tenantA] = $this->createTenant('a');
        [$tenantB] = $this->createTenant('b');
        $foreign = $this->category($tenantB, 'Autre entreprise');

        $this->expectException(QueryException::class);

        $this->makeArticle($tenantA, 'Riz local', ['category_id' => $foreign->id]);
    }
}