<?php

namespace Tests\Feature;

use App\Enums\TenantRole;
use App\Models\ArticlePackaging;
use App\Models\Article;
use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithStock;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class StockExitTest extends TestCase
{
    use InteractsWithStock, InteractsWithTenants;

    private const URL = '/api/v1/stock-exits';

    // --------------------------------------------------------------- Outils

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Tenant $tenant, array $lines, array $overrides = []): array
    {
        return array_merge([
            'reason' => 'loss',
            'exit_date' => $this->today($tenant),
            'warehouse_id' => $this->mainWarehouse($tenant)->id,
            'lines' => $lines,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function line(Article $article, string $quantity, array $overrides = []): array
    {
        return array_merge(['article_id' => $article->id, 'entered_quantity' => $quantity], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $overrides
     */
    private function draft(Tenant $tenant, array $lines, array $overrides = []): string
    {
        return $this->postJson(self::URL, $this->payload($tenant, $lines, $overrides))
            ->assertCreated()
            ->json('data.id');
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $overrides
     */
    private function validated(Tenant $tenant, array $lines, array $overrides = []): string
    {
        $id = $this->draft($tenant, $lines, $overrides);

        $this->postJson(self::URL."/{$id}/validate")->assertOk();

        return $id;
    }

    private function secondWarehouse(Tenant $tenant): Warehouse
    {
        $warehouse = (new Warehouse)->forceFill(['tenant_id' => $tenant->id, 'name' => 'Dépôt Nord']);
        $warehouse->save();

        return $warehouse;
    }

    private function remaining(Tenant $tenant, string $code): string
    {
        return rtrim(rtrim((string) DB::table('stock_lots')->where('tenant_id', $tenant->id)->where('code', $code)->value('quantity_remaining'), '0'), '.') ?: '0';
    }

    // ----------------------------------------------------- Droits, isolation

    public function test_exits_are_reserved_to_stock_managers(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);
        $rice = $this->article($tenant, 'Riz');
        $this->receive($tenant, $rice, '10');

        foreach ([TenantRole::Seller, TenantRole::Viewer] as $role) {
            $this->asMember($this->addMember($tenant, $role), $tenant);

            $this->getJson(self::URL)
                ->assertForbidden()
                ->assertJsonPath('message', 'Seuls le propriétaire, les administrateurs et les gestionnaires de stock peuvent gérer les sorties de stock.');
            $this->postJson(self::URL, $this->payload($tenant, [$this->line($rice, '1')]))->assertForbidden();
        }

        $this->asMember($this->addMember($tenant, TenantRole::StockManager), $tenant);
        $this->draft($tenant, [$this->line($rice, '1')]);
    }

    public function test_each_company_only_sees_its_own_exits(): void
    {
        [$tenantA, $ownerA] = $this->createTenant('a');
        [$tenantB, $ownerB] = $this->createTenant('b');

        $this->asMember($ownerB, $tenantB);
        $riceB = $this->article($tenantB, 'Riz');
        $this->receive($tenantB, $riceB, '10');
        $idB = $this->validated($tenantB, [$this->line($riceB, '2')]);

        $this->asMember($ownerA, $tenantA);

        $this->getJson(self::URL)->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson(self::URL."/{$idB}")->assertNotFound();
        $this->postJson(self::URL."/{$idB}/validate")->assertNotFound();
    }

    public function test_exits_require_a_session_and_a_writable_company(): void
    {
        $this->getJson(self::URL)->assertStatus(401);

        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);
        $rice = $this->article($tenant, 'Riz');
        $this->receive($tenant, $rice, '10');

        $tenant->forceFill(['status' => 'expired'])->save();

        $this->getJson(self::URL)->assertOk();
        $this->postJson(self::URL, $this->payload($tenant, [$this->line($rice, '1')]))->assertForbidden();
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
        $this->receive($tenant, $rice, '100');

        $response = $this->postJson(self::URL, $this->payload($tenant, [
            $this->line($rice, '2', ['packaging_id' => $packaging->id]),
        ]))->assertCreated();

        $response
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.number', null)
            ->assertJsonPath('data.total_cost', null)
            ->assertJsonPath('data.lines.0.quantity', '50')
            ->assertJsonPath('data.lines.0.entered_unit', 'Sac')
            ->assertJsonPath('data.can.validate', true)
            ->assertJsonPath('data.can.cancel', false);

        $this->getJson("/api/v1/articles/{$rice->id}/stock")->assertJsonPath('data.total', '100');
    }

    public function test_a_draft_can_be_edited_and_deleted_but_a_validated_exit_cannot(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);
        $rice = $this->article($tenant, 'Riz');
        $this->receive($tenant, $rice, '10');

        $id = $this->draft($tenant, [$this->line($rice, '1')]);

        $this->putJson(self::URL."/{$id}", $this->payload($tenant, [$this->line($rice, '3')], ['reason' => 'breakage']))
            ->assertOk()
            ->assertJsonPath('data.reason', 'breakage')
            ->assertJsonPath('data.lines.0.quantity', '3');

        $this->deleteJson(self::URL."/{$id}")->assertNoContent();

        $locked = $this->validated($tenant, [$this->line($rice, '1')]);

        $this->putJson(self::URL."/{$locked}", $this->payload($tenant, [$this->line($rice, '2')]))
            ->assertStatus(409)->assertJsonPath('code', 'exit_not_draft');
        $this->deleteJson(self::URL."/{$locked}")->assertStatus(409)->assertJsonPath('code', 'exit_not_draft');
        $this->postJson(self::URL."/{$locked}/validate")->assertStatus(409)->assertJsonPath('code', 'exit_not_draft');
    }

    public function test_header_rules(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);
        $rice = $this->article($tenant, 'Riz');
        $lines = [$this->line($rice, '1')];

        $this->postJson(self::URL, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'reason' => 'Choisissez le motif de la sortie.',
                'exit_date' => 'Indiquez la date de la sortie.',
                'warehouse_id' => "Choisissez l'entrepôt d'où part la marchandise.",
                'lines' => 'Ajoutez au moins une ligne à la sortie.',
            ]);

        $this->postJson(self::URL, $this->payload($tenant, $lines, ['reason' => 'supplier_return']))
            ->assertJsonValidationErrors(['supplier_id' => 'Choisissez le fournisseur à qui vous retournez la marchandise.']);

        $this->postJson(self::URL, $this->payload($tenant, $lines, ['reason' => 'other']))
            ->assertJsonValidationErrors(['notes' => 'Expliquez la raison de cette sortie dans les notes (5 caractères minimum).']);

        $this->postJson(self::URL, $this->payload($tenant, $lines, ['exit_date' => $this->today($tenant, 2)]))
            ->assertJsonValidationErrors(['exit_date' => 'La date de la sortie ne peut pas être dans le futur.']);

        $inactive = $this->secondWarehouse($tenant);
        $inactive->forceFill(['is_active' => false])->save();

        $this->postJson(self::URL, $this->payload($tenant, $lines, ['warehouse_id' => $inactive->id]))
            ->assertJsonValidationErrors(['warehouse_id' => 'Cet entrepôt est désactivé. Choisissez-en un autre.']);

        $this->postJson(self::URL, $this->payload($tenant, $lines, ['reason' => 'other', 'notes' => 'Marchandise confisquée par les douanes']))
            ->assertCreated();
    }

    public function test_line_rules(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac');
        $oil = $this->article($tenant, 'Huile', 'sac');
        $oilPackaging = (new ArticlePackaging)->forceFill([
            'tenant_id' => $tenant->id, 'article_id' => $oil->id, 'name' => 'Carton', 'quantity' => '12',
        ]);
        $oilPackaging->save();
        $old = $this->article($tenant, 'Ancien', 'sac', ['is_active' => false]);

        $this->postJson(self::URL, $this->payload($tenant, [$this->line($rice, '2.5')]))
            ->assertJsonValidationErrors(['lines.0.entered_quantity' => "Cette quantité représente 2.5 sac, mais l'unité « Sac » n'accepte pas les décimales."]);

        $this->postJson(self::URL, $this->payload($tenant, [$this->line($rice, 'abc')]))
            ->assertJsonValidationErrors(['lines.0.entered_quantity' => 'La quantité est invalide : utilisez des chiffres avec au plus 3 décimales.']);

        $this->postJson(self::URL, $this->payload($tenant, [$this->line($rice, '0')]))
            ->assertStatus(422)->assertJsonValidationErrors(['lines.0.entered_quantity']);

        $this->postJson(self::URL, $this->payload($tenant, [$this->line($rice, '1', ['packaging_id' => $oilPackaging->id])]))
            ->assertJsonValidationErrors(['lines.0.packaging_id' => "Ce conditionnement n'appartient pas à cet article."]);

        $this->postJson(self::URL, $this->payload($tenant, [$this->line($old, '1')]))
            ->assertJsonValidationErrors(['lines.0.article_id' => 'Cet article est désactivé : réactivez-le ou choisissez-en un autre.']);
    }

    public function test_the_lot_chosen_on_a_line_is_checked(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $milk = $this->article($tenant, 'Lait', 'sac', ['track_expiry' => true]);
        $sugar = $this->article($tenant, 'Sucre', 'sac');
        $north = $this->secondWarehouse($tenant);

        $this->receive($tenant, $milk, '10', '500', $this->today($tenant, 30));
        $this->receive($tenant, $sugar, '10');
        $this->receive($tenant, $milk, '10', '500', $this->today($tenant, 30), null, $north->id);
        $this->receive($tenant, $milk, '10', '500', $this->today($tenant, 40));

        $lotOf = fn (string $code) => DB::table('stock_lots')->where('tenant_id', $tenant->id)->where('code', $code)->value('id');

        $this->postJson(self::URL, $this->payload($tenant, [$this->line($milk, '1', ['lot_id' => $lotOf('LOT-000002')])]))
            ->assertJsonValidationErrors(['lines.0.lot_id' => "Ce lot n'appartient pas à cet article."]);

        $this->postJson(self::URL, $this->payload($tenant, [$this->line($milk, '1', ['lot_id' => $lotOf('LOT-000003')])]))
            ->assertJsonValidationErrors(['lines.0.lot_id' => 'Ce lot se trouve dans un autre entrepôt.']);

        DB::table('stock_lots')->where('code', 'LOT-000004')->update(['quantity_remaining' => '0']);

        $this->postJson(self::URL, $this->payload($tenant, [$this->line($milk, '1', ['lot_id' => $lotOf('LOT-000004')])]))
            ->assertJsonValidationErrors(['lines.0.lot_id' => 'Ce lot est épuisé.']);

        $this->postJson(self::URL, $this->payload($tenant, [$this->line($milk, '1', ['lot_id' => $lotOf('LOT-000001')])], ['reason' => 'expiry']))
            ->assertJsonValidationErrors(['lines.0.lot_id' => "Ce lot n'est pas expiré : une sortie pour péremption ne concerne que des lots expirés."]);

        $this->postJson(self::URL, $this->payload($tenant, [$this->line($milk, '1', ['lot_id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ'])]))
            ->assertJsonValidationErrors(['lines.0.lot_id' => 'Ce lot est introuvable.']);
    }

    // ----------------------------------------------------------- Prélèvement

    public function test_the_lot_that_expires_first_is_taken_first_and_costs_are_recorded(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac');
        $this->receive($tenant, $rice, '20', '600');
        $rice->forceFill(['track_expiry' => true])->save();
        $this->receive($tenant, $rice, '50', '500', $this->today($tenant, 30));
        $this->receive($tenant, $rice, '30', '550', $this->today($tenant, 90));

        $response = $this->postJson(self::URL, $this->payload($tenant, [$this->line($rice, '70')]))->assertCreated();
        $id = $response->json('data.id');

        $validated = $this->postJson(self::URL."/{$id}/validate")->assertOk();

        $validated
            ->assertJsonPath('data.number', 'SOR-000001')
            ->assertJsonPath('data.status', 'validated')
            ->assertJsonPath('data.total_cost', '36000')
            ->assertJsonPath('data.lines.0.cost_total', '36000');

        $this->assertSame(['LOT-000002', 'LOT-000003'], $validated->json('data.lines.0.allocations.*.lot.code'));
        $this->assertSame(['50', '20'], $validated->json('data.lines.0.allocations.*.quantity'));
        $this->assertSame(['25000', '11000'], $validated->json('data.lines.0.allocations.*.value'));

        $stock = $this->getJson("/api/v1/articles/{$rice->id}/stock")->assertOk();

        $this->assertSame(['LOT-000003', 'LOT-000001'], $stock->json('data.lots.*.code'));
        $this->assertSame(['10', '20'], $stock->json('data.lots.*.quantity_remaining'));

        $movements = DB::table('stock_movements')->where('type', 'exit')->pluck('quantity')->sort()->values()->all();
                $this->assertSame(['-50.000', '-20.000'], $movements);

        $second = $this->validated($tenant, [$this->line($rice, '20')], ['reason' => 'internal_use']);

        $this->getJson(self::URL."/{$second}")
            ->assertJsonPath('data.number', 'SOR-000002')
            ->assertJsonPath('data.total_cost', '11500');
    }

    public function test_expired_lots_are_only_taken_by_an_expiry_exit(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $milk = $this->article($tenant, 'Lait', 'sac', ['track_expiry' => true]);
        $entered = $this->today($tenant, -60);
        $this->receive($tenant, $milk, '10', '500', $this->today($tenant, -5), $entered);
        $this->receive($tenant, $milk, '20', '500', $this->today($tenant, 30), $entered);

        $tooMuch = $this->draft($tenant, [$this->line($milk, '25')]);

        $this->postJson(self::URL."/{$tooMuch}/validate")
            ->assertStatus(409)
            ->assertJsonPath('code', 'insufficient_stock')
            ->assertJsonPath('message', "Stock insuffisant pour « Lait » dans l'entrepôt « Entrepôt principal » : 20 sac disponible(s) (hors lots expirés), 25 sac demandé(s).");

        $expired = $this->draft($tenant, [$this->line($milk, '10')], ['reason' => 'expiry']);

        $this->postJson(self::URL."/{$expired}/validate")
            ->assertOk()
            ->assertJsonPath('data.lines.0.allocations.0.lot.code', 'LOT-000001');

        $this->assertSame('0', $this->remaining($tenant, 'LOT-000001'));
        $this->assertSame('20', $this->remaining($tenant, 'LOT-000002'));

        $again = $this->draft($tenant, [$this->line($milk, '1')], ['reason' => 'expiry']);

        $this->postJson(self::URL."/{$again}/validate")
            ->assertStatus(409)
            ->assertJsonPath('code', 'insufficient_expired_stock')
            ->assertJsonPath('message', "Stock expiré insuffisant pour « Lait » dans l'entrepôt « Entrepôt principal » : 0 sac expiré(s), 1 sac demandé(s).");
    }

    public function test_a_specific_lot_can_be_chosen(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $milk = $this->article($tenant, 'Lait', 'sac', ['track_expiry' => true]);
        $this->receive($tenant, $milk, '10', '500', $this->today($tenant, 10));
        $this->receive($tenant, $milk, '10', '500', $this->today($tenant, 60));

        $second = DB::table('stock_lots')->where('tenant_id', $tenant->id)->where('code', 'LOT-000002')->value('id');

        $id = $this->validated($tenant, [$this->line($milk, '4', ['lot_id' => $second])]);

        $this->getJson(self::URL."/{$id}")
            ->assertJsonPath('data.lines.0.lot.code', 'LOT-000002')
            ->assertJsonPath('data.lines.0.allocations.0.lot.code', 'LOT-000002')
            ->assertJsonPath('data.lines.0.allocations.0.quantity', '4');

        $this->assertSame('10', $this->remaining($tenant, 'LOT-000001'));
        $this->assertSame('6', $this->remaining($tenant, 'LOT-000002'));

        $tooMuch = $this->draft($tenant, [$this->line($milk, '50', ['lot_id' => $second])]);

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
        $this->receive($tenant, $rice, '10');
        $this->receive($tenant, $oil, '5');

        $id = $this->draft($tenant, [$this->line($rice, '4'), $this->line($oil, '9')]);

        $this->postJson(self::URL."/{$id}/validate")->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');

        $this->assertSame('10', $this->remaining($tenant, 'LOT-000001'));
        $this->assertSame('5', $this->remaining($tenant, 'LOT-000002'));
        $this->assertSame(0, DB::table('stock_movements')->where('type', 'exit')->count());
        $this->getJson(self::URL."/{$id}")->assertJsonPath('data.status', 'draft')->assertJsonPath('data.number', null);
    }

    public function test_only_the_stock_of_the_chosen_warehouse_is_used(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac');
        $north = $this->secondWarehouse($tenant);
        $this->receive($tenant, $rice, '10', '500', null, null, $north->id);

        $fromMain = $this->draft($tenant, [$this->line($rice, '5')]);

        $this->postJson(self::URL."/{$fromMain}/validate")
            ->assertStatus(409)
            ->assertJsonPath('message', "Stock insuffisant pour « Riz » dans l'entrepôt « Entrepôt principal » : 0 sac disponible(s) (hors lots expirés), 5 sac demandé(s).");

        $this->validated($tenant, [$this->line($rice, '5')], ['warehouse_id' => $north->id]);

        $this->assertSame('5', $this->remaining($tenant, 'LOT-000001'));
    }

    public function test_lines_of_the_same_article_use_the_stock_one_after_the_other(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac');
        $this->receive($tenant, $rice, '50');

        $id = $this->draft($tenant, [$this->line($rice, '30'), $this->line($rice, '30')]);

        $this->postJson(self::URL."/{$id}/validate")
            ->assertStatus(409)
            ->assertJsonPath('message', "Stock insuffisant pour « Riz » dans l'entrepôt « Entrepôt principal » : 20 sac disponible(s) (hors lots expirés), 30 sac demandé(s).");

        $this->assertSame('50', $this->remaining($tenant, 'LOT-000001'));

        $ok = $this->validated($tenant, [$this->line($rice, '30'), $this->line($rice, '20')]);

        $this->getJson(self::URL."/{$ok}")->assertJsonPath('data.total_cost', '25000');
        $this->assertSame('0', $this->remaining($tenant, 'LOT-000001'));
    }

    public function test_the_exit_date_cannot_precede_the_reception_of_the_lot(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac');
        $this->receive($tenant, $rice, '10');

        $id = $this->draft($tenant, [$this->line($rice, '1')], ['exit_date' => $this->today($tenant, -1)]);

        $this->postJson(self::URL."/{$id}/validate")
            ->assertStatus(409)
            ->assertJsonPath('code', 'exit_before_reception');

        $this->assertSame('10', $this->remaining($tenant, 'LOT-000001'));
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
        $this->receive($tenant, $rice, '100');

        $id = $this->draft($tenant, [$this->line($rice, '1', ['packaging_id' => $packaging->id])]);

        $packaging->forceFill(['quantity' => '50'])->save();

        $this->postJson(self::URL."/{$id}/validate")->assertStatus(409)->assertJsonPath('code', 'exit_outdated');

        $packaging->forceFill(['quantity' => '25'])->save();
        $rice->forceFill(['is_active' => false])->save();

        $this->postJson(self::URL."/{$id}/validate")->assertStatus(409)->assertJsonPath('code', 'article_inactive');

        $rice->forceFill(['is_active' => true])->save();

        $this->postJson(self::URL."/{$id}/validate")->assertOk()->assertJsonPath('data.lines.0.quantity', '25');
    }

    public function test_the_cost_is_rounded_lot_by_lot_in_the_currency(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'kg');
        $packaging = (new ArticlePackaging)->forceFill([
            'tenant_id' => $tenant->id, 'article_id' => $rice->id, 'name' => 'Sac', 'quantity' => '3',
        ]);
        $packaging->save();

        $entry = $this->postJson('/api/v1/stock-entries', [
            'reason' => 'opening_stock',
            'entry_date' => $this->today($tenant),
            'warehouse_id' => $this->mainWarehouse($tenant)->id,
            'lines' => [[
                'article_id' => $rice->id,
                'packaging_id' => $packaging->id,
                'entered_quantity' => '1',
                'unit_price' => '1000',
            ]],
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/stock-entries/{$entry}/validate")->assertOk();

        $one = $this->validated($tenant, [$this->line($rice, '1')]);
        $two = $this->validated($tenant, [$this->line($rice, '2')]);

        $this->getJson(self::URL."/{$one}")->assertJsonPath('data.total_cost', '333');
        $this->getJson(self::URL."/{$two}")->assertJsonPath('data.total_cost', '667');
    }

    // ------------------------------------------------------------ Annulation

    public function test_cancelling_restores_the_exact_lots_and_the_journal(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac', ['track_expiry' => true]);
        $this->receive($tenant, $rice, '10', '500', $this->today($tenant, 20));
        $this->receive($tenant, $rice, '10', '550', $this->today($tenant, 60));

        $id = $this->validated($tenant, [$this->line($rice, '15')]);

        $this->assertSame('0', $this->remaining($tenant, 'LOT-000001'));
        $this->assertSame('5', $this->remaining($tenant, 'LOT-000002'));

        $this->postJson(self::URL."/{$id}/cancel", ['reason' => 'Erreur de saisie'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancel_reason', 'Erreur de saisie')
            ->assertJsonPath('data.number', 'SOR-000001')
            ->assertJsonPath('data.can.cancel', false);

        $this->assertSame('10', $this->remaining($tenant, 'LOT-000001'));
        $this->assertSame('10', $this->remaining($tenant, 'LOT-000002'));

        $this->assertSame(2, DB::table('stock_movements')->where('type', 'exit')->count());
        $this->assertSame(2, DB::table('stock_movements')->where('type', 'exit_cancellation')->count());
        $this->assertEqualsWithDelta(
            20.0,
            (float) DB::table('stock_movements')->where('type', 'exit_cancellation')->sum('quantity') + 5.0,
            0.0001,
        );

        $this->postJson(self::URL."/{$id}/cancel", ['reason' => 'Encore'])
            ->assertStatus(409)->assertJsonPath('code', 'exit_not_validated');
    }

    public function test_only_owners_and_admins_can_cancel_and_a_reason_is_required(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac');
        $this->receive($tenant, $rice, '10');
        $id = $this->validated($tenant, [$this->line($rice, '2')]);
        $draft = $this->draft($tenant, [$this->line($rice, '1')]);

        $this->postJson(self::URL."/{$id}/cancel", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason' => 'Indiquez pourquoi vous annulez cette sortie.']);

        $this->postJson(self::URL."/{$draft}/cancel", ['reason' => 'Test'])
            ->assertStatus(409)->assertJsonPath('code', 'exit_not_validated');

        $this->asMember($this->addMember($tenant, TenantRole::StockManager), $tenant);

        $this->postJson(self::URL."/{$id}/cancel", ['reason' => 'Test'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Seuls le propriétaire et les administrateurs peuvent annuler une sortie validée.');

        $this->asMember($this->addMember($tenant, TenantRole::Admin), $tenant);

        $this->postJson(self::URL."/{$id}/cancel", ['reason' => 'Erreur de saisie'])->assertOk();
    }

    public function test_an_entry_cannot_be_cancelled_while_an_exit_used_its_lot(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac');
        $entry = $this->receive($tenant, $rice, '20');
        $exit = $this->validated($tenant, [$this->line($rice, '5')]);

        $this->postJson("/api/v1/stock-entries/{$entry}/cancel", ['reason' => 'Erreur de saisie'])
            ->assertStatus(409);

        $this->postJson(self::URL."/{$exit}/cancel", ['reason' => 'Erreur de saisie'])->assertOk();

        $this->postJson("/api/v1/stock-entries/{$entry}/cancel", ['reason' => 'Erreur de saisie'])->assertOk();
    }

    // ----------------------------------------------------------- Protections

    public function test_records_used_by_an_exit_are_protected(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac');
        $north = $this->secondWarehouse($tenant);
        $supplier = $this->postJson('/api/v1/suppliers', ['name' => 'Grossiste'])->assertCreated()->json('data.id');

        $this->receive($tenant, $rice, '10', '500', null, null, $north->id);
        $this->validated($tenant, [$this->line($rice, '1')], [
            'reason' => 'supplier_return',
            'supplier_id' => $supplier,
            'warehouse_id' => $north->id,
        ]);

        $this->deleteJson("/api/v1/suppliers/{$supplier}")->assertStatus(409);
        $this->deleteJson("/api/v1/warehouses/{$north->id}")->assertStatus(409);

        $fresh = $this->article($tenant, 'Nouveau', 'sac');
        $this->draft($tenant, [$this->line($fresh, '1')]);

        $this->deleteJson("/api/v1/articles/{$fresh->id}")->assertStatus(409);
    }

    public function test_the_list_supports_filters_and_pagination(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac');
        $this->receive($tenant, $rice, '100');

        $loss = $this->validated($tenant, [$this->line($rice, '1')], ['reference' => 'BORD-77']);
        $this->validated($tenant, [$this->line($rice, '1')], ['reason' => 'donation']);
        $this->draft($tenant, [$this->line($rice, '1')], ['reason' => 'breakage']);

        $this->getJson(self::URL)->assertOk()->assertJsonPath('meta.total', 3);
        $this->getJson(self::URL.'?status=draft')->assertJsonPath('meta.total', 1);
        $this->getJson(self::URL.'?reason=donation')->assertJsonPath('meta.total', 1);
        $this->getJson(self::URL.'?search=BORD-77')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $loss);
        $this->getJson(self::URL.'?search=SOR-000002')->assertJsonPath('meta.total', 1);
        $this->getJson(self::URL.'?status=inconnu')->assertStatus(422);
        $this->getJson(self::URL.'?from='.$this->today($tenant, 1))->assertJsonPath('meta.total', 0);
        $this->getJson(self::URL.'?warehouse_id='.$this->mainWarehouse($tenant)->id)->assertJsonPath('meta.total', 3);
    }

    // ------------------------------------------------- Méta et tableau de bord

    public function test_exit_reasons_are_exposed_to_the_frontend(): void
    {
        $response = $this->getJson('/api/v1/meta/stock')->assertOk();

        $reasons = collect($response->json('exit_reasons') ?? $response->json('data.exit_reasons'));

        $this->assertCount(7, $reasons);
        $this->assertTrue($reasons->firstWhere('value', 'supplier_return')['requires_supplier']);
        $this->assertTrue($reasons->firstWhere('value', 'other')['requires_notes']);
        $this->assertTrue($reasons->firstWhere('value', 'expiry')['expired_only']);
        $this->assertSame('Péremption', $reasons->firstWhere('value', 'expiry')['label']);
    }

    public function test_the_dashboard_reports_the_exits_of_the_last_30_days(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac');
        $this->receive($tenant, $rice, '10', '500');
        $this->validated($tenant, [$this->line($rice, '3')]);
        $this->draft($tenant, [$this->line($rice, '1')]);

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.exits.count_30d', 1)
            ->assertJsonPath('data.exits.total_30d', '1500');

        $this->asMember($this->addMember($tenant, TenantRole::Seller), $tenant);

        $this->getJson('/api/v1/dashboard')->assertJsonPath('data.exits', null);
    }

    // ------------------------------------------------ Garde-fous PostgreSQL

    public function test_the_database_protects_validated_exits(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac');
        $this->receive($tenant, $rice, '10');
        $id = $this->validated($tenant, [$this->line($rice, '2')]);

        $this->expectException(QueryException::class);

        DB::table('stock_exits')->where('id', $id)->update(['total_cost' => '1']);
    }

    public function test_the_database_protects_the_lines_of_validated_exits(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac');
        $this->receive($tenant, $rice, '10');
        $this->validated($tenant, [$this->line($rice, '2')]);

        $this->expectException(QueryException::class);

        DB::table('stock_exit_lines')->update(['quantity' => '1']);
    }

    public function test_the_database_protects_the_allocations(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac');
        $this->receive($tenant, $rice, '10');
        $this->validated($tenant, [$this->line($rice, '2')]);

        $this->expectException(QueryException::class);

        DB::table('stock_exit_allocations')->delete();
    }

    public function test_the_movement_journal_stays_immutable(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $rice = $this->article($tenant, 'Riz', 'sac');
        $this->receive($tenant, $rice, '10');
        $this->validated($tenant, [$this->line($rice, '2')]);

        $this->expectException(QueryException::class);

        DB::table('stock_movements')->where('type', 'exit')->update(['quantity' => '-1']);
    }
}