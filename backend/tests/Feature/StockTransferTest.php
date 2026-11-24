<?php

namespace Tests\Feature;

use App\Enums\TenantRole;
use App\Models\Article;
use App\Models\ArticlePackaging;
use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithSales;
use Tests\Concerns\InteractsWithStock;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class StockTransferTest extends TestCase
{
    use InteractsWithSales, InteractsWithStock, InteractsWithTenants;

    private const URL = '/api/v1/stock-transfers';

    // --------------------------------------------------------------- Outils

    private function warehouseNamed(Tenant $tenant, string $name = 'Dépôt Nord'): Warehouse
    {
        $warehouse = (new Warehouse)->forceFill(['tenant_id' => $tenant->id, 'name' => $name]);
        $warehouse->save();

        return $warehouse;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Tenant $tenant, Warehouse $to, array $lines, array $overrides = []): array
    {
        return array_merge([
            'transfer_date' => $this->today($tenant),
            'from_warehouse_id' => $this->mainWarehouse($tenant)->id,
            'to_warehouse_id' => $to->id,
            'lines' => $lines,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function tline(Article $article, string $quantity, array $overrides = []): array
    {
        return array_merge(['article_id' => $article->id, 'entered_quantity' => $quantity], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $overrides
     */
    private function draft(Tenant $tenant, Warehouse $to, array $lines, array $overrides = []): string
    {
        return $this->postJson(self::URL, $this->payload($tenant, $to, $lines, $overrides))
            ->assertCreated()
            ->json('data.id');
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $overrides
     */
    private function validated(Tenant $tenant, Warehouse $to, array $lines, array $overrides = []): string
    {
        $id = $this->draft($tenant, $to, $lines, $overrides);

        $this->postJson(self::URL."/{$id}/validate")->assertOk();

        return $id;
    }

    private function lotRow(Tenant $tenant, string $code): object
    {
        return DB::table('stock_lots')->where('tenant_id', $tenant->id)->where('code', $code)->firstOrFail();
    }

    // ----------------------------------------------------- Droits, isolation

    public function test_transfers_are_open_to_sellers_and_stock_staff_but_not_to_viewers(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);
        $rice = $this->article($tenant, 'Riz', 'sac');
        $north = $this->warehouseNamed($tenant);
        $this->receive($tenant, $rice, '50');

        $this->draft($tenant, $north, [$this->tline($rice, '1')]);

        foreach ([TenantRole::Admin, TenantRole::StockManager, TenantRole::Seller] as $role) {
            $this->asMember($this->addMember($tenant, $role), $tenant);
            $this->draft($tenant, $north, [$this->tline($rice, '1')]);
        }

        $this->asMember($this->addMember($tenant, TenantRole::Viewer), $tenant);

        $this->getJson(self::URL)->assertOk()->assertJsonPath('meta.can.create', false);
        $this->postJson(self::URL, $this->payload($tenant, $north, [$this->tline($rice, '1')]))
            ->assertForbidden()
            ->assertJsonPath('message', 'Seuls le propriétaire, les administrateurs, les gestionnaires de stock et les vendeurs peuvent gérer les transferts.');
    }

    public function test_each_company_only_sees_its_own_transfers(): void
    {
        [$tenantA, $ownerA] = $this->createTenant('a');
        [$tenantB, $ownerB] = $this->createTenant('b');

        $this->asMember($ownerB, $tenantB);
        $riceB = $this->article($tenantB, 'Riz', 'sac');
        $this->receive($tenantB, $riceB, '10');
        $idB = $this->validated($tenantB, $this->warehouseNamed($tenantB), [$this->tline($riceB, '2')]);

        $this->asMember($ownerA, $tenantA);

        $this->getJson(self::URL)->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson(self::URL."/{$idB}")->assertNotFound();
        $this->postJson(self::URL."/{$idB}/validate")->assertNotFound();
    }

    public function test_transfers_require_a_session_and_a_writable_company(): void
    {
        $this->getJson(self::URL)->assertStatus(401);

        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);
        $rice = $this->article($tenant, 'Riz', 'sac');
        $north = $this->warehouseNamed($tenant);
        $this->receive($tenant, $rice, '10');

        $tenant->forceFill(['status' => 'expired'])->save();

        $this->getJson(self::URL)->assertOk();
        $this->postJson(self::URL, $this->payload($tenant, $north, [$this->tline($rice, '1')]))->assertForbidden();
    }

    // ------------------------------------------------------------- Brouillon

    public function test_a_draft_converts_the_quantity_and_does_not_touch_the_stock(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'kg');
        $packaging = (new ArticlePackaging)->forceFill([
            'tenant_id' => $tenant->id, 'article_id' => $rice->id, 'name' => 'Sac', 'quantity' => '25',
        ]);
        $packaging->save();
        $north = $this->warehouseNamed($tenant);
        $this->receive($tenant, $rice, '100');

        $this->postJson(self::URL, $this->payload($tenant, $north, [
            $this->tline($rice, '2', ['packaging_id' => $packaging->id]),
        ]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.number', null)
            ->assertJsonPath('data.total_cost', null)
            ->assertJsonPath('data.lines.0.quantity', '50')
            ->assertJsonPath('data.lines.0.entered_unit', 'Sac')
            ->assertJsonPath('data.can.validate', true)
            ->assertJsonPath('data.can.cancel', false);

        $this->assertSame('100', $this->lotRemaining($tenant, 'LOT-000001'));
        $this->assertSame(1, DB::table('stock_lots')->count());
    }

    public function test_a_draft_can_be_edited_and_deleted_but_a_validated_transfer_cannot(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);
        $rice = $this->article($tenant, 'Riz', 'sac');
        $north = $this->warehouseNamed($tenant);
        $this->receive($tenant, $rice, '10');

        $id = $this->draft($tenant, $north, [$this->tline($rice, '1')]);

        $this->putJson(self::URL."/{$id}", $this->payload($tenant, $north, [$this->tline($rice, '3')], ['reference' => 'BT-12']))
            ->assertOk()
            ->assertJsonPath('data.reference', 'BT-12')
            ->assertJsonPath('data.lines.0.quantity', '3');

        $this->deleteJson(self::URL."/{$id}")->assertNoContent();

        $locked = $this->validated($tenant, $north, [$this->tline($rice, '1')]);

        $this->putJson(self::URL."/{$locked}", $this->payload($tenant, $north, [$this->tline($rice, '2')]))
            ->assertStatus(409)->assertJsonPath('code', 'transfer_not_draft');
        $this->deleteJson(self::URL."/{$locked}")->assertStatus(409)->assertJsonPath('code', 'transfer_not_draft');
        $this->postJson(self::URL."/{$locked}/validate")->assertStatus(409)->assertJsonPath('code', 'transfer_not_draft');
    }

    public function test_header_rules(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);
        $rice = $this->article($tenant, 'Riz', 'sac');
        $north = $this->warehouseNamed($tenant);
        $lines = [$this->tline($rice, '1')];

        $this->postJson(self::URL, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'transfer_date' => 'Indiquez la date du transfert.',
                'from_warehouse_id' => "Choisissez l'entrepôt d'origine.",
                'to_warehouse_id' => "Choisissez l'entrepôt de destination.",
                'lines' => 'Ajoutez au moins une ligne au transfert.',
            ]);

        $this->postJson(self::URL, $this->payload($tenant, $this->mainWarehouse($tenant), $lines))
            ->assertJsonValidationErrors(['to_warehouse_id' => "Choisissez deux entrepôts différents : la destination doit être autre que l'origine."]);

        $this->postJson(self::URL, $this->payload($tenant, $north, $lines, ['transfer_date' => $this->today($tenant, 2)]))
            ->assertJsonValidationErrors(['transfer_date' => 'La date du transfert ne peut pas être dans le futur.']);

        $inactive = $this->warehouseNamed($tenant, 'Dépôt fermé');
        $inactive->forceFill(['is_active' => false])->save();

        $this->postJson(self::URL, $this->payload($tenant, $inactive, $lines))
            ->assertJsonValidationErrors(['to_warehouse_id' => 'Cet entrepôt est désactivé. Choisissez-en un autre.']);

        $this->postJson(self::URL, $this->payload($tenant, $north, $lines, ['from_warehouse_id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ']))
            ->assertJsonValidationErrors(['from_warehouse_id' => 'Cet entrepôt est introuvable.']);
    }

    public function test_line_and_lot_rules(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $milk = $this->article($tenant, 'Lait', 'sac', ['track_expiry' => true]);
        $sugar = $this->article($tenant, 'Sucre', 'sac');
        $oil = $this->article($tenant, 'Huile', 'sac');
        $old = $this->article($tenant, 'Ancien', 'sac', ['is_active' => false]);
        $packaging = (new ArticlePackaging)->forceFill([
            'tenant_id' => $tenant->id, 'article_id' => $oil->id, 'name' => 'Carton', 'quantity' => '12',
        ]);
        $packaging->save();

        $north = $this->warehouseNamed($tenant);
        $entered = $this->today($tenant, -60);

        $this->receive($tenant, $milk, '10', '500', $this->today($tenant, 30), $entered);
        $this->receive($tenant, $sugar, '10');
        $this->receive($tenant, $milk, '10', '500', $this->today($tenant, 40), null, $north->id);
        $this->receive($tenant, $milk, '10', '500', $this->today($tenant, -5), $entered);
        $this->receive($tenant, $milk, '10', '500', $this->today($tenant, 50));

        $lotOf = fn (string $code) => $this->lotRow($tenant, $code)->id;

        $this->postJson(self::URL, $this->payload($tenant, $north, [$this->tline($sugar, '2.5')]))
            ->assertJsonValidationErrors(['lines.0.entered_quantity' => "Cette quantité représente 2.5 sac, mais l'unité « Sac » n'accepte pas les décimales."]);

        $this->postJson(self::URL, $this->payload($tenant, $north, [$this->tline($sugar, 'abc')]))
            ->assertJsonValidationErrors(['lines.0.entered_quantity' => 'La quantité est invalide : utilisez des chiffres avec au plus 3 décimales.']);

        $this->postJson(self::URL, $this->payload($tenant, $north, [$this->tline($sugar, '1', ['packaging_id' => $packaging->id])]))
            ->assertJsonValidationErrors(['lines.0.packaging_id' => "Ce conditionnement n'appartient pas à cet article."]);

        $this->postJson(self::URL, $this->payload($tenant, $north, [$this->tline($old, '1')]))
            ->assertJsonValidationErrors(['lines.0.article_id' => 'Cet article est désactivé : réactivez-le ou choisissez-en un autre.']);

        $this->postJson(self::URL, $this->payload($tenant, $north, [$this->tline($milk, '1', ['lot_id' => $lotOf('LOT-000002')])]))
            ->assertJsonValidationErrors(['lines.0.lot_id' => "Ce lot n'appartient pas à cet article."]);

        $this->postJson(self::URL, $this->payload($tenant, $north, [$this->tline($milk, '1', ['lot_id' => $lotOf('LOT-000003')])]))
            ->assertJsonValidationErrors(['lines.0.lot_id' => "Ce lot se trouve dans un autre entrepôt que l'entrepôt d'origine."]);

        $this->postJson(self::URL, $this->payload($tenant, $north, [$this->tline($milk, '1', ['lot_id' => $lotOf('LOT-000004')])]))
            ->assertJsonValidationErrors(['lines.0.lot_id' => 'Ce lot est expiré : un lot expiré ne se transfère pas, sortez-le pour péremption.']);

        DB::table('stock_lots')->where('code', 'LOT-000005')->update(['quantity_remaining' => '0']);

        $this->postJson(self::URL, $this->payload($tenant, $north, [$this->tline($milk, '1', ['lot_id' => $lotOf('LOT-000005')])]))
            ->assertJsonValidationErrors(['lines.0.lot_id' => 'Ce lot est épuisé.']);

        $this->postJson(self::URL, $this->payload($tenant, $north, [$this->tline($milk, '1', ['lot_id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ'])]))
            ->assertJsonValidationErrors(['lines.0.lot_id' => 'Ce lot est introuvable.']);
    }

    // ------------------------------------------------------------ Validation

    public function test_validation_moves_the_stock_and_keeps_expiry_lot_number_and_cost(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac', ['track_expiry' => true]);
        $north = $this->warehouseNamed($tenant);
        $expiry = $this->today($tenant, 60);

        $this->receive($tenant, $rice, '50', '500', $expiry);
        DB::table('stock_lots')->where('code', 'LOT-000001')->update(['lot_number' => 'FAB-77']);

        $before = $this->getJson('/api/v1/dashboard')->assertOk()->json('data.stock_value');
        $this->assertSame('25000', $before);

        $id = $this->draft($tenant, $north, [$this->tline($rice, '20')]);

        $response = $this->postJson(self::URL."/{$id}/validate")->assertOk();

        $response
            ->assertJsonPath('data.number', 'TRF-000001')
            ->assertJsonPath('data.status', 'validated')
            ->assertJsonPath('data.total_cost', '10000')
            ->assertJsonPath('data.lines.0.cost_total', '10000')
            ->assertJsonPath('data.lines.0.allocations.0.source_lot.code', 'LOT-000001')
            ->assertJsonPath('data.lines.0.allocations.0.destination_lot.code', 'LOT-000002')
            ->assertJsonPath('data.lines.0.allocations.0.quantity', '20')
            ->assertJsonPath('data.lines.0.allocations.0.source_lot.expiry_date', $expiry)
            ->assertJsonPath('data.can.cancel', true)
            ->assertJsonPath('data.can.validate', false);

        $this->assertSame('30', $this->lotRemaining($tenant, 'LOT-000001'));
        $this->assertSame('20', $this->lotRemaining($tenant, 'LOT-000002'));

        $destination = $this->lotRow($tenant, 'LOT-000002');
        $source = $this->lotRow($tenant, 'LOT-000001');

        $this->assertSame($north->id, $destination->warehouse_id);
        $this->assertSame($expiry, $destination->expiry_date);
        $this->assertSame('FAB-77', $destination->lot_number);
        $this->assertSame($source->id, $destination->source_lot_id);
        $this->assertNull($destination->stock_entry_line_id);
        $this->assertNotNull($destination->stock_transfer_line_id);
        $this->assertEqualsWithDelta(500.0, (float) $destination->unit_cost, 0.000001);
        $this->assertEqualsWithDelta(20.0, (float) $destination->quantity_initial, 0.000001);

        $out = DB::table('stock_movements')->where('type', 'transfer_out')->pluck('quantity')->all();
        $in = DB::table('stock_movements')->where('type', 'transfer_in')->pluck('quantity')->all();

        $this->assertSame(['-20.000'], $out);
        $this->assertSame(['20.000'], $in);

        $stock = $this->getJson("/api/v1/articles/{$rice->id}/stock")->assertOk();

        $stock->assertJsonPath('data.total', '50');
        $this->assertCount(2, $stock->json('data.lots'));
        $this->assertCount(2, $stock->json('data.warehouses'));

        $this->getJson('/api/v1/dashboard')->assertJsonPath('data.stock_value', '25000');
    }

    public function test_the_lot_that_expires_first_is_moved_first(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac', ['track_expiry' => true]);
        $north = $this->warehouseNamed($tenant);

        $this->receive($tenant, $rice, '5', '500', $this->today($tenant, 30));
        $this->receive($tenant, $rice, '10', '550', $this->today($tenant, 90));

        $response = $this->postJson(self::URL.'/'.$this->draft($tenant, $north, [$this->tline($rice, '8')]).'/validate')->assertOk();

        $this->assertSame(['LOT-000001', 'LOT-000002'], $response->json('data.lines.0.allocations.*.source_lot.code'));
        $this->assertSame(['LOT-000003', 'LOT-000004'], $response->json('data.lines.0.allocations.*.destination_lot.code'));
        $this->assertSame(['5', '3'], $response->json('data.lines.0.allocations.*.quantity'));
        $response->assertJsonPath('data.total_cost', '4150');

        $this->assertSame('0', $this->lotRemaining($tenant, 'LOT-000001'));
        $this->assertSame('7', $this->lotRemaining($tenant, 'LOT-000002'));
        $this->assertSame($this->today($tenant, 30), $this->lotRow($tenant, 'LOT-000003')->expiry_date);
        $this->assertSame($this->today($tenant, 90), $this->lotRow($tenant, 'LOT-000004')->expiry_date);
    }

    public function test_expired_lots_are_never_transferred_automatically(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $milk = $this->article($tenant, 'Lait', 'sac', ['track_expiry' => true]);
        $north = $this->warehouseNamed($tenant);
        $entered = $this->today($tenant, -60);

        $this->receive($tenant, $milk, '10', '500', $this->today($tenant, -5), $entered);
        $this->receive($tenant, $milk, '20', '500', $this->today($tenant, 30), $entered);

        $tooMuch = $this->draft($tenant, $north, [$this->tline($milk, '25')]);

        $this->postJson(self::URL."/{$tooMuch}/validate")
            ->assertStatus(409)
            ->assertJsonPath('code', 'insufficient_stock')
            ->assertJsonPath('message', "Stock insuffisant pour « Lait » dans l'entrepôt « Entrepôt principal » : 20 sac disponible(s) (hors lots expirés), 25 sac demandé(s).");

        $ok = $this->draft($tenant, $north, [$this->tline($milk, '15')]);

        $this->postJson(self::URL."/{$ok}/validate")
            ->assertOk()
            ->assertJsonPath('data.lines.0.allocations.0.source_lot.code', 'LOT-000002');

        $this->assertSame('10', $this->lotRemaining($tenant, 'LOT-000001'));
    }

    public function test_a_specific_lot_can_be_chosen(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $milk = $this->article($tenant, 'Lait', 'sac', ['track_expiry' => true]);
        $north = $this->warehouseNamed($tenant);

        $this->receive($tenant, $milk, '10', '500', $this->today($tenant, 10));
        $this->receive($tenant, $milk, '10', '500', $this->today($tenant, 60));

        $second = $this->lotRow($tenant, 'LOT-000002')->id;

        $id = $this->validated($tenant, $north, [$this->tline($milk, '4', ['lot_id' => $second])]);

        $this->getJson(self::URL."/{$id}")
            ->assertJsonPath('data.lines.0.lot.code', 'LOT-000002')
            ->assertJsonPath('data.lines.0.allocations.0.source_lot.code', 'LOT-000002')
            ->assertJsonPath('data.lines.0.allocations.0.destination_lot.code', 'LOT-000003');

        $this->assertSame('10', $this->lotRemaining($tenant, 'LOT-000001'));
        $this->assertSame('6', $this->lotRemaining($tenant, 'LOT-000002'));

        $tooMuch = $this->draft($tenant, $north, [$this->tline($milk, '50', ['lot_id' => $second])]);

        $this->postJson(self::URL."/{$tooMuch}/validate")
            ->assertStatus(409)
            ->assertJsonPath('code', 'lot_insufficient')
            ->assertJsonPath('message', 'Le lot LOT-000002 ne contient que 6 sac, mais 50 sac sont demandés.');
    }

    public function test_an_insufficient_line_changes_nothing(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac');
        $oil = $this->article($tenant, 'Huile', 'sac');
        $north = $this->warehouseNamed($tenant);
        $this->receive($tenant, $rice, '10');
        $this->receive($tenant, $oil, '5');

        $id = $this->draft($tenant, $north, [$this->tline($rice, '4'), $this->tline($oil, '9')]);

        $this->postJson(self::URL."/{$id}/validate")->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');

        $this->assertSame('10', $this->lotRemaining($tenant, 'LOT-000001'));
        $this->assertSame('5', $this->lotRemaining($tenant, 'LOT-000002'));
        $this->assertSame(2, DB::table('stock_lots')->count());
        $this->assertSame(0, DB::table('stock_movements')->where('type', 'like', 'transfer%')->count());
        $this->getJson(self::URL."/{$id}")->assertJsonPath('data.status', 'draft')->assertJsonPath('data.number', null);
    }

    public function test_lines_of_the_same_article_use_the_stock_one_after_the_other(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac');
        $north = $this->warehouseNamed($tenant);
        $this->receive($tenant, $rice, '50');

        $id = $this->draft($tenant, $north, [$this->tline($rice, '30'), $this->tline($rice, '30')]);

        $this->postJson(self::URL."/{$id}/validate")
            ->assertStatus(409)
            ->assertJsonPath('message', "Stock insuffisant pour « Riz » dans l'entrepôt « Entrepôt principal » : 20 sac disponible(s) (hors lots expirés), 30 sac demandé(s).");

        $this->assertSame('50', $this->lotRemaining($tenant, 'LOT-000001'));
    }

    public function test_the_transfer_date_cannot_precede_the_reception_of_the_lot(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac');
        $north = $this->warehouseNamed($tenant);
        $this->receive($tenant, $rice, '10');

        $id = $this->draft($tenant, $north, [$this->tline($rice, '1')], ['transfer_date' => $this->today($tenant, -1)]);

        $this->postJson(self::URL."/{$id}/validate")
            ->assertStatus(409)
            ->assertJsonPath('code', 'transfer_before_reception');

        $this->assertSame('10', $this->lotRemaining($tenant, 'LOT-000001'));
    }

    public function test_validation_checks_the_draft_again(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'kg');
        $packaging = (new ArticlePackaging)->forceFill([
            'tenant_id' => $tenant->id, 'article_id' => $rice->id, 'name' => 'Sac', 'quantity' => '25',
        ]);
        $packaging->save();
        $north = $this->warehouseNamed($tenant);
        $this->receive($tenant, $rice, '100');

        $id = $this->draft($tenant, $north, [$this->tline($rice, '1', ['packaging_id' => $packaging->id])]);

        $packaging->forceFill(['quantity' => '50'])->save();
        $this->postJson(self::URL."/{$id}/validate")->assertStatus(409)->assertJsonPath('code', 'transfer_outdated');

        $packaging->forceFill(['quantity' => '25'])->save();
        $rice->forceFill(['is_active' => false])->save();
        $this->postJson(self::URL."/{$id}/validate")->assertStatus(409)->assertJsonPath('code', 'article_inactive');

        $rice->forceFill(['is_active' => true])->save();
        $north->forceFill(['is_active' => false])->save();
        $this->postJson(self::URL."/{$id}/validate")->assertStatus(409)->assertJsonPath('code', 'warehouse_inactive');

        $north->forceFill(['is_active' => true])->save();
        $this->postJson(self::URL."/{$id}/validate")->assertOk()->assertJsonPath('data.lines.0.quantity', '25');
    }

    // ------------------------------------------------- Stock arrivé, annulation

    public function test_transferred_stock_can_be_sold_from_the_destination(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac', ['sale_price' => '700']);
        $north = $this->warehouseNamed($tenant);
        $this->receive($tenant, $rice, '50');

        $this->validated($tenant, $north, [$this->tline($rice, '20')]);

        $this->sell($tenant, [$this->saleLine($rice, '5')], [$this->pay('cash', '3500')], ['warehouse_id' => $north->id])
            ->assertCreated()
            ->assertJsonPath('data.lines.0.allocations.0.lot.code', 'LOT-000002')
            ->assertJsonPath('data.cost_total', '2500');

        $this->assertSame('15', $this->lotRemaining($tenant, 'LOT-000002'));

        $this->sell($tenant, [$this->saleLine($rice, '35')], [$this->pay('cash', '24500')])
            ->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
    }

    public function test_cancelling_restores_the_source_and_voids_the_destination(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac');
        $north = $this->warehouseNamed($tenant);
        $this->receive($tenant, $rice, '50');

        $id = $this->validated($tenant, $north, [$this->tline($rice, '20')]);

        $this->postJson(self::URL."/{$id}/cancel", ['reason' => 'Erreur de saisie'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancel_reason', 'Erreur de saisie')
            ->assertJsonPath('data.number', 'TRF-000001')
            ->assertJsonPath('data.can.cancel', false);

        $this->assertSame('50', $this->lotRemaining($tenant, 'LOT-000001'));
        $this->assertSame('0', $this->lotRemaining($tenant, 'LOT-000002'));
        $this->assertNotNull($this->lotRow($tenant, 'LOT-000002')->voided_at);

        $this->assertSame(['20.000'], DB::table('stock_movements')->where('type', 'transfer_out_cancellation')->pluck('quantity')->all());
        $this->assertSame(['-20.000'], DB::table('stock_movements')->where('type', 'transfer_in_cancellation')->pluck('quantity')->all());

        $this->getJson('/api/v1/stock/articles?warehouse_id='.$north->id)->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson("/api/v1/articles/{$rice->id}/stock")->assertJsonPath('data.total', '50');

        $this->postJson(self::URL."/{$id}/cancel", ['reason' => 'Encore'])
            ->assertStatus(409)->assertJsonPath('code', 'transfer_not_validated');
    }

    public function test_a_transfer_cannot_be_cancelled_once_the_arrived_stock_was_used(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac', ['sale_price' => '700']);
        $north = $this->warehouseNamed($tenant);
        $this->receive($tenant, $rice, '50');

        $id = $this->validated($tenant, $north, [$this->tline($rice, '20')]);

        $saleId = $this->sell($tenant, [$this->saleLine($rice, '5')], [$this->pay('cash', '3500')], ['warehouse_id' => $north->id])
            ->assertCreated()
            ->json('data.id');

        $this->postJson(self::URL."/{$id}/cancel", ['reason' => 'Erreur de saisie'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'transfer_lots_used')
            ->assertJsonPath('message', "Le stock arrivé dans « Dépôt Nord » a déjà été utilisé (vendu, sorti ou transféré) : ce transfert ne peut plus être annulé. Lot concerné : LOT-000002.");

        $this->assertSame('30', $this->lotRemaining($tenant, 'LOT-000001'));
        $this->assertSame('15', $this->lotRemaining($tenant, 'LOT-000002'));

        $this->postJson("/api/v1/sales/{$saleId}/cancel", ['reason' => 'Erreur de saisie'])->assertOk();

        $this->postJson(self::URL."/{$id}/cancel", ['reason' => 'Erreur de saisie'])->assertOk();
    }

    public function test_only_owners_and_admins_can_cancel_and_a_reason_is_required(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac');
        $north = $this->warehouseNamed($tenant);
        $this->receive($tenant, $rice, '50');

        $id = $this->validated($tenant, $north, [$this->tline($rice, '5')]);
        $draft = $this->draft($tenant, $north, [$this->tline($rice, '1')]);

        $this->postJson(self::URL."/{$id}/cancel", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason' => 'Indiquez pourquoi vous annulez ce transfert.']);

        $this->postJson(self::URL."/{$draft}/cancel", ['reason' => 'Test'])
            ->assertStatus(409)->assertJsonPath('code', 'transfer_not_validated');

        foreach ([TenantRole::Seller, TenantRole::StockManager] as $role) {
            $this->asMember($this->addMember($tenant, $role), $tenant);

            $this->postJson(self::URL."/{$id}/cancel", ['reason' => 'Test'])
                ->assertForbidden()
                ->assertJsonPath('message', 'Seuls le propriétaire et les administrateurs peuvent annuler un transfert validé.');
        }

        $this->asMember($this->addMember($tenant, TenantRole::Admin), $tenant);

        $this->postJson(self::URL."/{$id}/cancel", ['reason' => 'Erreur de saisie'])->assertOk();
    }

    public function test_an_entry_cannot_be_cancelled_while_a_transfer_moved_its_stock(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac');
        $north = $this->warehouseNamed($tenant);
        $entry = $this->receive($tenant, $rice, '20');

        $transfer = $this->validated($tenant, $north, [$this->tline($rice, '5')]);

        $this->postJson("/api/v1/stock-entries/{$entry}/cancel", ['reason' => 'Erreur de saisie'])->assertStatus(409);

        $this->postJson(self::URL."/{$transfer}/cancel", ['reason' => 'Erreur de saisie'])->assertOk();

        $this->postJson("/api/v1/stock-entries/{$entry}/cancel", ['reason' => 'Erreur de saisie'])->assertOk();
    }

    // ----------------------------------------------- Coûts, protections, liste

    public function test_costs_are_hidden_from_sellers(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac');
        $north = $this->warehouseNamed($tenant);
        $this->receive($tenant, $rice, '50', '500');
        $id = $this->validated($tenant, $north, [$this->tline($rice, '20')]);

        $this->getJson(self::URL."/{$id}")
            ->assertJsonPath('data.total_cost', '10000')
            ->assertJsonPath('data.lines.0.allocations.0.cost', '10000');

        $this->asMember($this->addMember($tenant, TenantRole::Seller), $tenant);

        $this->getJson(self::URL."/{$id}")
            ->assertOk()
            ->assertJsonPath('data.lines.0.allocations.0.quantity', '20')
            ->assertJsonMissingPath('data.total_cost')
            ->assertJsonMissingPath('data.lines.0.cost_total')
            ->assertJsonMissingPath('data.lines.0.allocations.0.unit_cost')
            ->assertJsonMissingPath('data.lines.0.allocations.0.cost');

        $this->getJson(self::URL)->assertOk()->assertJsonMissingPath('data.0.total_cost');
    }

    public function test_records_used_by_a_transfer_are_protected(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac');
        $north = $this->warehouseNamed($tenant);
        $south = $this->warehouseNamed($tenant, 'Dépôt Sud');
        $this->receive($tenant, $rice, '50');

        $id = $this->validated($tenant, $north, [$this->tline($rice, '5')]);
        $this->postJson(self::URL."/{$id}/cancel", ['reason' => 'Erreur de saisie'])->assertOk();

        $this->deleteJson("/api/v1/warehouses/{$north->id}")->assertStatus(409);

        $fresh = $this->article($tenant, 'Nouveau', 'sac');
        $this->draft($tenant, $south, [$this->tline($fresh, '1')]);

        $this->deleteJson("/api/v1/warehouses/{$south->id}")->assertStatus(409);
        $this->deleteJson("/api/v1/articles/{$fresh->id}")->assertStatus(409);
    }

    public function test_the_list_supports_filters_and_pagination(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac');
        $north = $this->warehouseNamed($tenant);
        $south = $this->warehouseNamed($tenant, 'Dépôt Sud');
        $this->receive($tenant, $rice, '100');

        $first = $this->validated($tenant, $north, [$this->tline($rice, '1')], ['reference' => 'BT-77']);
        $this->validated($tenant, $south, [$this->tline($rice, '1')]);
        $this->draft($tenant, $north, [$this->tline($rice, '1')]);

        $this->getJson(self::URL)->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('meta.can.create', true);
        $this->getJson(self::URL.'?status=draft')->assertJsonPath('meta.total', 1);
        $this->getJson(self::URL.'?status=validated')->assertJsonPath('meta.total', 2);
        $this->getJson(self::URL."?warehouse_id={$north->id}")->assertJsonPath('meta.total', 2);
        $this->getJson(self::URL."?warehouse_id={$south->id}")->assertJsonPath('meta.total', 1);
        $this->getJson(self::URL.'?warehouse_id='.$this->mainWarehouse($tenant)->id)->assertJsonPath('meta.total', 3);
        $this->getJson(self::URL.'?search=BT-77')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $first);
        $this->getJson(self::URL.'?search=TRF-000002')->assertJsonPath('meta.total', 1);
        $this->getJson(self::URL.'?from='.$this->today($tenant, 1))->assertJsonPath('meta.total', 0);
        $this->getJson(self::URL.'?status=inconnu')->assertStatus(422);
    }

    // ------------------------------------------------ Garde-fous PostgreSQL

    private function oneTransfer(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac');
        $this->receive($tenant, $rice, '10');
        $this->validated($tenant, $this->warehouseNamed($tenant), [$this->tline($rice, '2')]);
    }

    public function test_the_database_protects_a_validated_transfer(): void
    {
        $this->oneTransfer();

        $this->expectException(QueryException::class);

        DB::table('stock_transfers')->update(['total_cost' => '1']);
    }

    public function test_the_database_protects_the_lines_of_a_validated_transfer(): void
    {
        $this->oneTransfer();

        $this->expectException(QueryException::class);

        DB::table('stock_transfer_lines')->update(['quantity' => '1']);
    }

    public function test_the_database_protects_the_allocations(): void
    {
        $this->oneTransfer();

        $this->expectException(QueryException::class);

        DB::table('stock_transfer_allocations')->delete();
    }

    public function test_the_database_protects_the_origin_of_a_transferred_lot(): void
    {
        $this->oneTransfer();

        $this->expectException(QueryException::class);

        DB::table('stock_lots')->where('code', 'LOT-000002')->update(['source_lot_id' => null]);
    }

    public function test_the_database_refuses_a_lot_without_any_origin(): void
    {
        $this->oneTransfer();

        $this->expectException(QueryException::class);

        DB::table('stock_lots')->where('code', 'LOT-000002')->update(['stock_transfer_line_id' => null]);
    }
}