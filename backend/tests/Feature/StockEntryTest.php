<?php

namespace Tests\Feature;

use App\Enums\TenantRole;
use App\Enums\TenantStatus;
use App\Models\Article;
use App\Models\ArticlePackaging;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\SequenceGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class StockEntryTest extends TestCase
{
    use InteractsWithTenants;

    private const URL = '/api/v1/stock-entries';

    private function unit(Tenant $tenant, string $symbol): Unit
    {
        return Unit::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('symbol_key', Unit::symbolKey($symbol))
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function article(Tenant $tenant, string $name, string $unitSymbol = 'sac', array $attributes = []): Article
    {
        $article = (new Article)->forceFill(array_merge([
            'tenant_id' => $tenant->id,
            'code' => sprintf('ART-%06d', app(SequenceGenerator::class)->next($tenant->id, 'article')),
            'name' => $name,
            'unit_id' => $this->unit($tenant, $unitSymbol)->id,
            'sale_price' => '500',
        ], $attributes));
        $article->save();

        return $article;
    }

    private function packaging(Article $article, string $name, string $quantity): ArticlePackaging
    {
        $packaging = (new ArticlePackaging)->forceFill([
            'tenant_id' => $article->tenant_id,
            'article_id' => $article->id,
            'name' => $name,
            'quantity' => $quantity,
        ]);
        $packaging->save();

        return $packaging;
    }

    private function supplier(Tenant $tenant, string $name = 'Riz Afrique'): Supplier
    {
        $supplier = (new Supplier)->forceFill(['tenant_id' => $tenant->id, 'name' => $name]);
        $supplier->save();

        return $supplier;
    }

    private function mainWarehouse(Tenant $tenant): Warehouse
    {
        return Warehouse::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('is_main', true)->firstOrFail();
    }

    private function secondWarehouse(Tenant $tenant, string $name = 'Dépôt Nord'): Warehouse
    {
        $warehouse = (new Warehouse)->forceFill(['tenant_id' => $tenant->id, 'name' => $name]);
        $warehouse->save();

        return $warehouse;
    }

    private function today(Tenant $tenant): string
    {
        return now($tenant->timezone)->toDateString();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function line(Article $article, array $overrides = []): array
    {
        return array_merge([
            'article_id' => $article->id,
            'entered_quantity' => '10',
            'unit_price' => '500',
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Tenant $tenant, array $lines, array $overrides = []): array
    {
        return array_merge([
            'reason' => 'purchase',
            'entry_date' => $this->today($tenant),
            'warehouse_id' => $this->mainWarehouse($tenant)->id,
            'supplier_id' => $this->supplier($tenant, 'Fournisseur '.uniqid())->id,
            'lines' => $lines,
        ], $overrides);
    }

    private function draft(Tenant $tenant, array $lines, array $overrides = []): string
    {
        return $this->postJson(self::URL, $this->payload($tenant, $lines, $overrides))
            ->assertCreated()
            ->json('data.id');
    }

    private function validated(Tenant $tenant, array $lines, array $overrides = []): string
    {
        $id = $this->draft($tenant, $lines, $overrides);
        $this->postJson(self::URL."/{$id}/validate")->assertOk();

        return $id;
    }

    // --------------------------------------------------- Accès, isolation, droits

    public function test_entries_are_reserved_to_stock_managers(): void
    {
        [$tenant] = $this->createTenant();
        $denied = 'Seuls le propriétaire, les administrateurs et les gestionnaires de stock peuvent gérer les entrées de stock.';

        foreach ([TenantRole::Seller, TenantRole::Viewer] as $role) {
            $this->asMember($this->addMember($tenant, $role), $tenant);

            $this->getJson(self::URL)->assertForbidden()->assertJsonPath('message', $denied);
            $this->postJson(self::URL, [])->assertForbidden();
        }

        $article = $this->article($tenant, 'Riz local');

        $this->asMember($this->addMember($tenant, TenantRole::StockManager), $tenant);
        $this->postJson(self::URL, $this->payload($tenant, [$this->line($article)]))->assertCreated();
    }

    public function test_each_company_only_sees_and_touches_its_own_entries(): void
    {
        [$tenantA, $ownerA] = $this->createTenant('a');
        [$tenantB, $ownerB] = $this->createTenant('b');
        $articleB = $this->article($tenantB, 'Riz local');

        $this->asMember($ownerB, $tenantB);
        $foreign = $this->draft($tenantB, [$this->line($articleB)]);

        $this->asMember($ownerA, $tenantA);

        $this->getJson(self::URL)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::URL."/{$foreign}")->assertNotFound();
        $this->deleteJson(self::URL."/{$foreign}")->assertNotFound();
        $this->postJson(self::URL."/{$foreign}/validate")->assertNotFound();
    }

    // ------------------------------------------------------------ Brouillon

    public function test_a_draft_converts_quantities_and_has_no_effect_on_stock(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $rice = $this->article($tenant, 'Riz au kilo', 'kg', ['track_expiry' => true]);
        $sac = $this->packaging($rice, 'Sac', '25');

        $this->asMember($owner, $tenant);

        $response = $this->postJson(self::URL, $this->payload($tenant, [$this->line($rice, [
            'packaging_id' => $sac->id,
            'entered_quantity' => '10',
            'unit_price' => '12500',
            'expiry_date' => now()->addMonths(6)->toDateString(),
        ])], ['reference' => 'BL-2041']))
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.number', null)
            ->assertJsonPath('data.reference', 'BL-2041')
            ->assertJsonPath('data.lines.0.entered_unit', 'Sac')
            ->assertJsonPath('data.lines.0.factor', '25')
            ->assertJsonPath('data.lines.0.entered_quantity', '10')
            ->assertJsonPath('data.lines.0.quantity', '250')
            ->assertJsonPath('data.lines.0.line_total', '125000')
            ->assertJsonPath('data.total_amount', '125000')
            ->assertJsonPath('data.can.validate', true);

        $this->getJson("/api/v1/articles/{$rice->id}/stock")->assertOk()->assertJsonPath('data.total', '0');
        $this->assertNotNull($response->json('data.id'));
    }

    public function test_amounts_are_rounded_half_up_to_the_currency_decimals(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $rice = $this->article($tenant, 'Riz au kilo', 'kg');
        $sac = $this->packaging($rice, 'Sac', '25');

        $this->asMember($owner, $tenant);

        $this->postJson(self::URL, $this->payload($tenant, [$this->line($rice, [
            'packaging_id' => $sac->id,
            'entered_quantity' => '2.5',
            'unit_price' => '333',
        ])]))
            ->assertCreated()
            ->assertJsonPath('data.lines.0.quantity', '62.5')
            ->assertJsonPath('data.lines.0.line_total', '833');
    }

    public function test_header_rules(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $article = $this->article($tenant, 'Riz local');
        $inactiveWarehouse = $this->secondWarehouse($tenant);
        $inactiveWarehouse->forceFill(['is_active' => false])->save();
        $inactiveSupplier = $this->supplier($tenant, 'Ancien fournisseur');
        $inactiveSupplier->forceFill(['is_active' => false])->save();

        $this->asMember($owner, $tenant);

        $this->postJson(self::URL, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'reason' => "Choisissez le motif de l'entrée.",
                'entry_date' => "Indiquez la date de l'entrée.",
                'warehouse_id' => "Choisissez l'entrepôt qui reçoit la marchandise.",
                'lines' => "Ajoutez au moins une ligne à l'entrée.",
            ]);

        $this->postJson(self::URL, $this->payload($tenant, [$this->line($article)], ['supplier_id' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['supplier_id' => "Choisissez le fournisseur d'un achat."]);

        $this->postJson(self::URL, $this->payload($tenant, [$this->line($article)], [
            'warehouse_id' => $inactiveWarehouse->id,
            'supplier_id' => $inactiveSupplier->id,
            'entry_date' => now($tenant->timezone)->addDay()->toDateString(),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'warehouse_id' => 'Cet entrepôt est désactivé. Choisissez-en un autre.',
                'supplier_id' => 'Ce fournisseur est désactivé. Choisissez-en un autre.',
                'entry_date' => "La date de l'entrée ne peut pas être dans le futur.",
            ]);

        $this->postJson(self::URL, $this->payload($tenant, [$this->line($article)], ['supplier_id' => null, 'reason' => 'opening_stock']))
            ->assertCreated();
    }

    public function test_line_rules(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $perishable = $this->article($tenant, 'Lait', 'sac', ['track_expiry' => true]);
        $plain = $this->article($tenant, 'Balai', 'pce');
        $kg = $this->article($tenant, 'Sucre', 'kg');
        $otherPackaging = $this->packaging($kg, 'Sac', '50');
        $inactive = $this->article($tenant, 'Ancien produit');
        $inactive->forceFill(['is_active' => false])->save();
        $today = $this->today($tenant);

        $this->asMember($owner, $tenant);

        $errors = fn (array $line, array $overrides = []) => $this->postJson(self::URL, $this->payload($tenant, [$line], $overrides))
            ->assertStatus(422);

        $errors($this->line($plain, ['unit_price' => '0']))
            ->assertJsonValidationErrors(['lines.0.unit_price' => "Le prix d'achat doit être supérieur à zéro (seul un don peut être à zéro)."]);

        $errors($this->line($plain, ['unit_price' => '500.5']))
            ->assertJsonValidationErrors(['lines.0.unit_price' => "Le prix d'achat ne peut pas avoir de décimales en XOF."]);

        $errors($this->line($plain, ['unit_price' => 'abc']))
            ->assertJsonValidationErrors(['lines.0.unit_price' => "Le prix d'achat doit être un montant valide, par exemple 500."]);

        $errors($this->line($plain, ['entered_quantity' => '2.5']))
            ->assertJsonValidationErrors(['lines.0.entered_quantity' => "Cette quantité représente 2.5 pce, mais l'unité « Pièce » n'accepte pas les décimales."]);

        $errors($this->line($plain, ['entered_quantity' => '0']))
            ->assertJsonValidationErrors(['lines.0.entered_quantity' => 'La quantité doit être supérieure à zéro.']);

        $errors($this->line($perishable))
            ->assertJsonValidationErrors(['lines.0.expiry_date' => "Indiquez la date d'expiration : cet article est périssable."]);

        $errors($this->line($perishable, ['expiry_date' => now($tenant->timezone)->subDay()->toDateString()]))
            ->assertJsonValidationErrors(['lines.0.expiry_date' => "La date d'expiration ne peut pas être antérieure à la date de l'entrée."]);

        $errors($this->line($plain, ['expiry_date' => $today]))
            ->assertJsonValidationErrors(['lines.0.expiry_date' => "Cet article n'a pas de date d'expiration : laissez ce champ vide."]);

        $errors($this->line($plain, ['packaging_id' => $otherPackaging->id]))
            ->assertJsonValidationErrors(['lines.0.packaging_id' => "Ce conditionnement n'appartient pas à cet article."]);

        $errors($this->line($inactive))
            ->assertJsonValidationErrors(['lines.0.article_id' => 'Cet article est désactivé. Réactivez-le ou retirez cette ligne.']);

        $errors($this->line($plain, ['sale_price' => '600.5']))
            ->assertJsonValidationErrors(['lines.0.sale_price' => 'Le prix de vente ne peut pas avoir de décimales en XOF.']);
    }

    public function test_a_donation_may_have_a_zero_price(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $article = $this->article($tenant, 'Échantillon', 'pce');

        $this->asMember($owner, $tenant);

        $this->postJson(self::URL, $this->payload($tenant, [$this->line($article, ['unit_price' => '0'])], [
            'reason' => 'donation',
            'supplier_id' => null,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.reason_label', 'Don ou échantillon')
            ->assertJsonPath('data.lines.0.line_total', '0');
    }

    public function test_a_draft_can_be_replaced_and_deleted_but_a_validated_entry_cannot(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $rice = $this->article($tenant, 'Riz local');
        $oil = $this->article($tenant, 'Huile', 'bidon');

        $this->asMember($owner, $tenant);

        $id = $this->draft($tenant, [$this->line($rice)]);

        $this->putJson(self::URL."/{$id}", $this->payload($tenant, [
            $this->line($oil, ['entered_quantity' => '4', 'unit_price' => '15000']),
            $this->line($rice, ['entered_quantity' => '1']),
        ]))
            ->assertOk()
            ->assertJsonCount(2, 'data.lines')
            ->assertJsonPath('data.lines.0.article.name', 'Huile')
            ->assertJsonPath('data.total_amount', '60500');

        $this->deleteJson(self::URL."/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('stock_entries', ['id' => $id]);

        $validated = $this->validated($tenant, [$this->line($rice)]);

        $this->putJson(self::URL."/{$validated}", $this->payload($tenant, [$this->line($rice)]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'entry_not_draft');

        $this->deleteJson(self::URL."/{$validated}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'entry_not_draft');
    }

    // ------------------------------------------------------------ Validation

    public function test_validation_creates_lots_updates_stock_and_keeps_the_history_of_prices(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $rice = $this->article($tenant, 'Riz local', 'sac', ['track_expiry' => true]);

        $this->asMember($owner, $tenant);

        $first = $this->postJson(self::URL, $this->payload($tenant, [$this->line($rice, [
            'entered_quantity' => '50',
            'unit_price' => '500',
            'expiry_date' => now($tenant->timezone)->addMonths(2)->toDateString(),
            'lot_number' => 'L-A',
        ])]))->assertCreated()->json('data.id');

        $this->postJson(self::URL."/{$first}/validate")
            ->assertOk()
            ->assertJsonPath('data.status', 'validated')
            ->assertJsonPath('data.number', 'ENT-000001')
            ->assertJsonPath('data.total_amount', '25000')
            ->assertJsonPath('data.lines.0.lot.code', 'LOT-000001')
            ->assertJsonPath('data.can.update', false)
            ->assertJsonPath('data.can.cancel', true);

        $second = $this->postJson(self::URL, $this->payload($tenant, [$this->line($rice, [
            'entered_quantity' => '30',
            'unit_price' => '550',
            'sale_price' => '650',
            'expiry_date' => now($tenant->timezone)->addMonths(5)->toDateString(),
        ])]))->assertCreated()->json('data.id');

        $this->postJson(self::URL."/{$second}/validate")
            ->assertOk()
            ->assertJsonPath('data.number', 'ENT-000002');

        $this->postJson(self::URL."/{$second}/validate")
            ->assertStatus(409)
            ->assertJsonPath('code', 'entry_not_draft');

        $stock = $this->getJson("/api/v1/articles/{$rice->id}/stock")->assertOk();

        $this->assertSame('80', $stock->json('data.total'));
        $this->assertSame(['LOT-000001', 'LOT-000002'], $stock->json('data.lots.*.code'));
        $this->assertSame(['50', '30'], $stock->json('data.lots.*.quantity_remaining'));
        $this->assertSame(['500', '550'], $stock->json('data.lots.*.unit_cost'));
        $this->assertSame(['25000', '16500'], $stock->json('data.lots.*.value'));
        $this->assertSame('L-A', $stock->json('data.lots.0.lot_number'));
        $this->assertSame('80', $stock->json('data.warehouses.0.quantity'));

                $this->assertSame('650', $this->getJson("/api/v1/articles/{$rice->id}")->json('data.sale_price'));

        // L'article a été préparé directement en base, donc sans « prix initial » : seul le changement fait par l'entrée est tracé.
        $history = $this->getJson("/api/v1/articles/{$rice->id}/price-history")->assertOk();

        $this->assertSame(['650'], $history->json('data.*.new_price'));
        $this->assertSame(['500'], $history->json('data.*.old_price'));

        $this->assertSame(2, DB::table('stock_movements')->where('article_id', $rice->id)->count());
    }

    public function test_the_unit_cost_follows_the_base_unit_when_entering_in_a_packaging(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $rice = $this->article($tenant, 'Riz au kilo', 'kg');
        $sac = $this->packaging($rice, 'Sac', '25');

        $this->asMember($owner, $tenant);

        $this->validated($tenant, [$this->line($rice, [
            'packaging_id' => $sac->id,
            'entered_quantity' => '10',
            'unit_price' => '12500',
        ])]);

        $stock = $this->getJson("/api/v1/articles/{$rice->id}/stock")->assertOk();

        $this->assertSame('250', $stock->json('data.total'));
        $this->assertSame('kg', $stock->json('data.unit.symbol'));
        $this->assertSame('500', $stock->json('data.lots.0.unit_cost'));
        $this->assertSame('125000', $stock->json('data.lots.0.value'));
    }

    public function test_validation_is_rechecked_against_the_current_state_of_the_data(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $rice = $this->article($tenant, 'Riz au kilo', 'kg');
        $sac = $this->packaging($rice, 'Sac', '25');

        $this->asMember($owner, $tenant);

        $id = $this->draft($tenant, [$this->line($rice, ['packaging_id' => $sac->id, 'unit_price' => '12500'])]);

        $sac->forceFill(['quantity' => '50'])->save();

        $this->postJson(self::URL."/{$id}/validate")
            ->assertStatus(409)
            ->assertJsonPath('code', 'entry_outdated');

        $this->getJson("/api/v1/articles/{$rice->id}/stock")->assertJsonPath('data.total', '0');

        $rice->forceFill(['is_active' => false])->save();
        $sac->forceFill(['quantity' => '25'])->save();

        $this->postJson(self::URL."/{$id}/validate")
            ->assertStatus(409)
            ->assertJsonPath('code', 'article_inactive');
    }

    // ------------------------------------------------------------ Annulation

    public function test_only_owner_and_admin_can_cancel_and_a_reason_is_required(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $rice = $this->article($tenant, 'Riz local');

        $this->asMember($owner, $tenant);
        $id = $this->validated($tenant, [$this->line($rice)]);

        $this->asMember($this->addMember($tenant, TenantRole::StockManager), $tenant);
        $this->postJson(self::URL."/{$id}/cancel", ['reason' => 'Erreur de saisie'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Seuls le propriétaire et les administrateurs peuvent annuler une entrée validée.');

        $this->asMember($owner, $tenant);
        $this->postJson(self::URL."/{$id}/cancel", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason' => "Indiquez pourquoi vous annulez cette entrée."]);

        $draft = $this->draft($tenant, [$this->line($rice)]);
        $this->postJson(self::URL."/{$draft}/cancel", ['reason' => 'Test'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'entry_not_validated');
    }

    public function test_cancelling_removes_the_stock_and_keeps_the_history(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $rice = $this->article($tenant, 'Riz local');

        $this->asMember($owner, $tenant);
        $id = $this->validated($tenant, [$this->line($rice, ['entered_quantity' => '20'])]);

        $this->postJson(self::URL."/{$id}/cancel", ['reason' => 'Livraison refusée'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancel_reason', 'Livraison refusée')
            ->assertJsonPath('data.number', 'ENT-000001')
            ->assertJsonPath('data.can.cancel', false);

        $this->getJson("/api/v1/articles/{$rice->id}/stock")
            ->assertOk()
            ->assertJsonPath('data.total', '0')
            ->assertJsonCount(0, 'data.lots');

        $this->assertSame(['20.000', '-20.000'], DB::table('stock_movements')->orderBy('id')->pluck('quantity')->all());
        $this->assertNotNull(DB::table('stock_lots')->value('voided_at'));

        $this->postJson(self::URL."/{$id}/cancel", ['reason' => 'Encore'])->assertStatus(409);
    }

    public function test_an_entry_whose_goods_already_moved_cannot_be_cancelled(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $rice = $this->article($tenant, 'Riz local');

        $this->asMember($owner, $tenant);
        $id = $this->validated($tenant, [$this->line($rice, ['entered_quantity' => '20'])]);

        DB::table('stock_lots')->update(['quantity_remaining' => '15']);

        $this->postJson(self::URL."/{$id}/cancel", ['reason' => 'Erreur'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'entry_lots_used');
    }

    // ------------------------------------------------- Protections des usages

    public function test_warehouses_suppliers_and_articles_in_use_are_protected(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $rice = $this->article($tenant, 'Riz local');
        $warehouse = $this->secondWarehouse($tenant);
        $supplier = $this->supplier($tenant);

        $this->asMember($owner, $tenant);

        $id = $this->validated($tenant, [$this->line($rice)], [
            'warehouse_id' => $warehouse->id,
            'supplier_id' => $supplier->id,
        ]);

        $this->postJson("/api/v1/warehouses/{$warehouse->id}/deactivate")
            ->assertStatus(409)
            ->assertJsonPath('code', 'warehouse_has_stock');

        $this->deleteJson("/api/v1/warehouses/{$warehouse->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'warehouse_used');

        $this->deleteJson("/api/v1/suppliers/{$supplier->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'supplier_used');

        $this->deleteJson("/api/v1/articles/{$rice->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'article_used');

        $this->putJson("/api/v1/articles/{$rice->id}", [
            'name' => 'Riz local',
            'unit_id' => $this->unit($tenant, 'kg')->id,
            'sale_price' => '500',
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'unit_locked');

        $this->postJson(self::URL."/{$id}/cancel", ['reason' => 'Test'])->assertOk();

        $this->postJson("/api/v1/warehouses/{$warehouse->id}/deactivate")->assertOk();
    }

    // ------------------------------------------------------------ Liste

    public function test_the_list_supports_filters_and_pagination(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $rice = $this->article($tenant, 'Riz local');
        $north = $this->secondWarehouse($tenant);
        $supplier = $this->supplier($tenant, 'Riz Afrique');

        $this->asMember($owner, $tenant);

        $first = $this->validated($tenant, [$this->line($rice)], ['supplier_id' => $supplier->id, 'reference' => 'BL-777']);
        $this->draft($tenant, [$this->line($rice)], ['warehouse_id' => $north->id, 'reason' => 'opening_stock', 'supplier_id' => null]);

        $this->assertCount(2, $this->getJson(self::URL)->json('data'));
        $this->assertSame([$first], $this->getJson(self::URL.'?status=validated')->json('data.*.id'));
        $this->assertSame(['draft'], $this->getJson(self::URL.'?reason=opening_stock')->json('data.*.status'));
        $this->assertSame([$first], $this->getJson(self::URL.'?search=BL-777')->json('data.*.id'));
        $this->assertSame([$first], $this->getJson(self::URL.'?search=afrique')->json('data.*.id'));
        $this->assertSame([$first], $this->getJson(self::URL.'?search=ENT-000001')->json('data.*.id'));
        $this->assertCount(1, $this->getJson(self::URL.'?warehouse_id='.$north->id)->json('data'));
        $this->assertCount(0, $this->getJson(self::URL.'?from='.now($tenant->timezone)->addDay()->toDateString())->json('data'));

        $this->getJson(self::URL)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.can.create', true)
            ->assertJsonPath('data.0.lines_count', 1);
    }

    public function test_the_stock_endpoint_hides_costs_from_roles_without_access(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $rice = $this->article($tenant, 'Riz local');

        $this->asMember($owner, $tenant);
        $this->validated($tenant, [$this->line($rice, ['entered_quantity' => '10'])]);

        $this->getJson("/api/v1/articles/{$rice->id}/stock")->assertJsonPath('data.lots.0.unit_cost', '500');

        $this->asMember($this->addMember($tenant, TenantRole::Seller), $tenant);

        $response = $this->getJson("/api/v1/articles/{$rice->id}/stock")
            ->assertOk()
            ->assertJsonPath('data.total', '10');

        $this->assertArrayNotHasKey('unit_cost', $response->json('data.lots.0'));
        $this->assertArrayNotHasKey('value', $response->json('data.lots.0'));
    }

    // ------------------------------------------------------------ Le bon

    public function test_the_document_can_be_attached_served_replaced_and_removed(): void
    {
        Storage::fake(config('filesystems.files_disk'));

        [$tenant, $owner] = $this->createTenant();
        $rice = $this->article($tenant, 'Riz local');

        $this->asMember($owner, $tenant);
        $id = $this->validated($tenant, [$this->line($rice)]);

        $first = $this->post(self::URL."/{$id}/document", [
            'document' => UploadedFile::fake()->create('bon-livraison.pdf', 300, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.document.name', 'bon-livraison.pdf');

        $url = $first->json('data.document.url');
        $path = DB::table('stock_entries')->where('id', $id)->value('document_path');

        Storage::disk(config('filesystems.files_disk'))->assertExists($path);
        $this->get($url)->assertOk();
        $this->get($url.'x')->assertForbidden();

        $this->post(self::URL."/{$id}/document", [
            'document' => UploadedFile::fake()->image('photo-bon.jpg', 300, 300)->size(400),
        ], ['Accept' => 'application/json'])->assertOk();

        Storage::disk(config('filesystems.files_disk'))->assertMissing($path);

        $this->deleteJson(self::URL."/{$id}/document")->assertNoContent();
        $this->assertNull(DB::table('stock_entries')->where('id', $id)->value('document_path'));
    }

    public function test_the_document_must_be_a_small_pdf_or_image_and_not_on_a_cancelled_entry(): void
    {
        Storage::fake(config('filesystems.files_disk'));

        [$tenant, $owner] = $this->createTenant();
        $rice = $this->article($tenant, 'Riz local');

        $this->asMember($owner, $tenant);
        $id = $this->validated($tenant, [$this->line($rice)]);

        $this->post(self::URL."/{$id}/document", [
            'document' => UploadedFile::fake()->create('gros.pdf', 3000, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['document' => 'Le fichier ne peut pas dépasser 2 Mo.']);

        $this->post(self::URL."/{$id}/document", [
            'document' => UploadedFile::fake()->create('bon.docx', 100, 'application/msword'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['document' => 'Le bon doit être un PDF ou une image (JPG, PNG, WebP).']);

        $this->postJson(self::URL."/{$id}/cancel", ['reason' => 'Test'])->assertOk();

        $this->post(self::URL."/{$id}/document", [
            'document' => UploadedFile::fake()->create('bon.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'entry_cancelled');
    }

    // ------------------------------------------------- Meta, lecture seule

    public function test_the_meta_endpoint_lists_reasons_with_their_rules(): void
    {
        $response = $this->getJson('/api/v1/meta/stock')->assertOk();

        $purchase = collect($response->json('entry_reasons'))->firstWhere('value', 'purchase');
        $donation = collect($response->json('entry_reasons'))->firstWhere('value', 'donation');

        $this->assertTrue($purchase['requires_supplier']);
        $this->assertFalse($purchase['allows_zero_price']);
        $this->assertTrue($donation['allows_zero_price']);
        $this->assertCount(5, $response->json('entry_reasons'));
        $this->assertCount(3, $response->json('entry_statuses'));
    }

    public function test_an_expired_company_can_read_but_not_write(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $rice = $this->article($tenant, 'Riz local');

        $this->asMember($owner, $tenant);
        $id = $this->draft($tenant, [$this->line($rice)]);

        $tenant->forceFill(['status' => TenantStatus::Expired])->save();

        $this->getJson(self::URL)->assertOk()->assertJsonPath('meta.can.create', false);
        $this->getJson(self::URL."/{$id}")->assertOk()->assertJsonPath('data.can.validate', false);

        $this->postJson(self::URL, $this->payload($tenant, [$this->line($rice)]))
            ->assertForbidden()
            ->assertJsonPath('code', 'tenant_read_only');

        $this->postJson(self::URL."/{$id}/validate")->assertForbidden();
    }

    // ------------------------------------------------------ Garde-fous de la base

    public function test_the_database_refuses_to_change_the_movement_journal(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);
        $this->validated($tenant, [$this->line($this->article($tenant, 'Riz local'))]);

        $this->expectException(QueryException::class);

        DB::table('stock_movements')->update(['quantity' => '999']);
    }

    public function test_the_database_refuses_to_delete_from_the_movement_journal(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);
        $this->validated($tenant, [$this->line($this->article($tenant, 'Riz local'))]);

        $this->expectException(QueryException::class);

        DB::table('stock_movements')->delete();
    }

    public function test_the_database_refuses_to_change_the_lines_of_a_validated_entry(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);
        $this->validated($tenant, [$this->line($this->article($tenant, 'Riz local'))]);

        $this->expectException(QueryException::class);

        DB::table('stock_entry_lines')->update(['quantity' => '999']);
    }

    public function test_the_database_refuses_to_change_the_origin_of_a_lot(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);
        $this->validated($tenant, [$this->line($this->article($tenant, 'Riz local'))]);

        $this->expectException(QueryException::class);

        DB::table('stock_lots')->update(['quantity_initial' => '999', 'quantity_remaining' => '999']);
    }

    public function test_the_database_refuses_to_delete_or_rewrite_a_validated_entry(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);
        $id = $this->validated($tenant, [$this->line($this->article($tenant, 'Riz local'))]);

        $this->expectException(QueryException::class);

        DB::table('stock_entries')->where('id', $id)->update(['total_amount' => '1']);
    }

    public function test_the_database_refuses_to_delete_a_validated_entry(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);
        $id = $this->validated($tenant, [$this->line($this->article($tenant, 'Riz local'))]);

        $this->expectException(QueryException::class);

        DB::table('stock_entries')->where('id', $id)->delete();
    }
}