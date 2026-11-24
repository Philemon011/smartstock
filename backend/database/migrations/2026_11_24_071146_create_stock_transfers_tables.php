<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 20)->nullable();
            $table->string('status', 20)->default('draft');
            $table->date('transfer_date');
            $table->foreignUlid('from_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignUlid('to_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('reference', 60)->nullable();
            $table->string('notes', 500)->nullable();
            $table->decimal('total_cost', 19, 4)->nullable();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->foreignUlid('validated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignUlid('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancel_reason', 300)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'transfer_date']);
            $table->index('from_warehouse_id');
            $table->index('to_warehouse_id');
        });

        DB::statement("ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfers_status_check CHECK (status IN ('draft', 'validated', 'cancelled'))");
        DB::statement("ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfers_number_check CHECK (status = 'draft' OR number IS NOT NULL)");
        DB::statement('ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfers_warehouses_check CHECK (from_warehouse_id <> to_warehouse_id)');

        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->foreignUlid('article_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('packaging_id')->nullable()->constrained('article_packagings')->nullOnDelete();
            $table->boolean('in_packaging')->default(false);
            $table->string('entered_unit', 50);
            $table->decimal('factor', 18, 3);
            $table->decimal('entered_quantity', 18, 3);
            $table->decimal('quantity', 18, 3);
            $table->foreignUlid('lot_id')->nullable()->constrained('stock_lots')->noActionOnDelete();
            $table->decimal('cost_total', 19, 4)->nullable();
            $table->timestamps();

            $table->index('stock_transfer_id');
            $table->index('article_id');
            $table->index('lot_id');
        });

        DB::statement('ALTER TABLE stock_transfer_lines ADD CONSTRAINT stock_transfer_lines_positive_check CHECK (factor > 0 AND entered_quantity > 0 AND quantity > 0)');

        Schema::create('stock_transfer_allocations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('stock_transfer_line_id')->constrained('stock_transfer_lines')->cascadeOnDelete();
            $table->foreignUlid('source_lot_id')->constrained('stock_lots')->noActionOnDelete();
            $table->foreignUlid('destination_lot_id')->constrained('stock_lots')->noActionOnDelete();
            $table->decimal('quantity', 18, 3);
            $table->decimal('unit_cost', 19, 6);
            $table->decimal('cost', 19, 4);
            $table->timestamp('created_at')->useCurrent();

            $table->index('stock_transfer_line_id');
            $table->index('source_lot_id');
            $table->index('destination_lot_id');
        });

        DB::statement('ALTER TABLE stock_transfer_allocations ADD CONSTRAINT stock_transfer_allocations_check CHECK (quantity > 0 AND unit_cost >= 0 AND cost >= 0)');

        // Un lot arrivé par transfert n'a pas de ligne d'entrée : il pointe vers la ligne de transfert et le lot d'origine.
        DB::statement('ALTER TABLE stock_lots ALTER COLUMN stock_entry_line_id DROP NOT NULL');

        Schema::table('stock_lots', function (Blueprint $table) {
            $table->foreignUlid('stock_transfer_line_id')->nullable()->constrained('stock_transfer_lines')->noActionOnDelete();
            $table->foreignUlid('source_lot_id')->nullable()->constrained('stock_lots')->noActionOnDelete();
            $table->index('stock_transfer_line_id');
            $table->index('source_lot_id');
        });

        DB::statement('ALTER TABLE stock_lots ADD CONSTRAINT stock_lots_single_origin CHECK ((stock_entry_line_id IS NOT NULL) <> (stock_transfer_line_id IS NOT NULL))');
        DB::statement('ALTER TABLE stock_lots ADD CONSTRAINT stock_lots_source_matches_origin CHECK ((stock_transfer_line_id IS NULL) = (source_lot_id IS NULL))');

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignUlid('stock_transfer_id')->nullable()->constrained('stock_transfers')->noActionOnDelete();
            $table->index('stock_transfer_id');
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_stock_lots() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF EXISTS (SELECT 1 FROM tenants WHERE id = OLD.tenant_id) THEN
            RAISE EXCEPTION 'Un lot ne peut pas être supprimé.' USING ERRCODE = 'check_violation';
        END IF;

        RETURN OLD;
    END IF;

    IF NEW.article_id <> OLD.article_id
       OR NEW.warehouse_id <> OLD.warehouse_id
       OR NEW.stock_entry_line_id IS DISTINCT FROM OLD.stock_entry_line_id
       OR NEW.stock_transfer_line_id IS DISTINCT FROM OLD.stock_transfer_line_id
       OR NEW.source_lot_id IS DISTINCT FROM OLD.source_lot_id
       OR NEW.quantity_initial <> OLD.quantity_initial
       OR NEW.total_cost <> OLD.total_cost
       OR NEW.unit_cost <> OLD.unit_cost THEN
        RAISE EXCEPTION 'Les données d''origine d''un lot ne peuvent pas être modifiées.' USING ERRCODE = 'check_violation';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION guard_stock_transfers() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.status <> 'draft' AND EXISTS (SELECT 1 FROM tenants WHERE id = OLD.tenant_id) THEN
            RAISE EXCEPTION 'Un transfert validé ne peut pas être supprimé : annulez-le.' USING ERRCODE = 'check_violation';
        END IF;
        RETURN OLD;
    END IF;

    IF OLD.status = 'cancelled' AND NEW.status <> 'cancelled' THEN
        RAISE EXCEPTION 'Un transfert annulé ne peut plus être modifié.' USING ERRCODE = 'check_violation';
    END IF;

    IF OLD.status = 'validated' AND NEW.status = 'draft' THEN
        RAISE EXCEPTION 'Un transfert validé ne peut pas redevenir un brouillon.' USING ERRCODE = 'check_violation';
    END IF;

    IF OLD.status <> 'draft' AND (
        NEW.from_warehouse_id <> OLD.from_warehouse_id
        OR NEW.to_warehouse_id <> OLD.to_warehouse_id
        OR NEW.transfer_date <> OLD.transfer_date
        OR NEW.total_cost IS DISTINCT FROM OLD.total_cost
        OR NEW.number IS DISTINCT FROM OLD.number
    ) THEN
        RAISE EXCEPTION 'Un transfert validé ne peut pas être modifié.' USING ERRCODE = 'check_violation';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER stock_transfers_guard BEFORE UPDATE OR DELETE ON stock_transfers
FOR EACH ROW EXECUTE FUNCTION guard_stock_transfers();

CREATE OR REPLACE FUNCTION guard_stock_transfer_lines() RETURNS trigger AS $$
DECLARE
    parent_status text;
BEGIN
    IF TG_OP = 'INSERT' THEN
        SELECT status INTO parent_status FROM stock_transfers WHERE id = NEW.stock_transfer_id;
        IF parent_status <> 'draft' THEN
            RAISE EXCEPTION 'Les lignes d''un transfert validé ne peuvent pas être modifiées.' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END IF;

    SELECT status INTO parent_status FROM stock_transfers WHERE id = OLD.stock_transfer_id;

    IF parent_status IS NOT NULL AND parent_status <> 'draft' AND EXISTS (SELECT 1 FROM tenants WHERE id = OLD.tenant_id) THEN
        RAISE EXCEPTION 'Les lignes d''un transfert validé ne peuvent pas être modifiées.' USING ERRCODE = 'check_violation';
    END IF;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER stock_transfer_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON stock_transfer_lines
FOR EACH ROW EXECUTE FUNCTION guard_stock_transfer_lines();

CREATE OR REPLACE FUNCTION guard_stock_transfer_allocations() RETURNS trigger AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM tenants WHERE id = OLD.tenant_id) THEN
        RAISE EXCEPTION 'Le détail des lots d''un transfert ne peut pas être modifié.' USING ERRCODE = 'check_violation';
    END IF;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER stock_transfer_allocations_guard BEFORE UPDATE OR DELETE ON stock_transfer_allocations
FOR EACH ROW EXECUTE FUNCTION guard_stock_transfer_allocations();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS stock_transfer_allocations_guard ON stock_transfer_allocations;
DROP TRIGGER IF EXISTS stock_transfer_lines_guard ON stock_transfer_lines;
DROP TRIGGER IF EXISTS stock_transfers_guard ON stock_transfers;
DROP FUNCTION IF EXISTS guard_stock_transfer_allocations();
DROP FUNCTION IF EXISTS guard_stock_transfer_lines();
DROP FUNCTION IF EXISTS guard_stock_transfers();
SQL);

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_transfer_id');
        });

        DB::statement('ALTER TABLE stock_lots DROP CONSTRAINT IF EXISTS stock_lots_source_matches_origin');
        DB::statement('ALTER TABLE stock_lots DROP CONSTRAINT IF EXISTS stock_lots_single_origin');

        Schema::table('stock_lots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_lot_id');
            $table->dropConstrainedForeignId('stock_transfer_line_id');
        });

        DB::statement('ALTER TABLE stock_lots ALTER COLUMN stock_entry_line_id SET NOT NULL');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_stock_lots() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF EXISTS (SELECT 1 FROM tenants WHERE id = OLD.tenant_id) THEN
            RAISE EXCEPTION 'Un lot ne peut pas être supprimé.' USING ERRCODE = 'check_violation';
        END IF;

        RETURN OLD;
    END IF;

    IF NEW.article_id <> OLD.article_id
       OR NEW.warehouse_id <> OLD.warehouse_id
       OR NEW.stock_entry_line_id <> OLD.stock_entry_line_id
       OR NEW.quantity_initial <> OLD.quantity_initial
       OR NEW.total_cost <> OLD.total_cost
       OR NEW.unit_cost <> OLD.unit_cost THEN
        RAISE EXCEPTION 'Les données d''origine d''un lot ne peuvent pas être modifiées.' USING ERRCODE = 'check_violation';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        Schema::dropIfExists('stock_transfer_allocations');
        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');
    }
};