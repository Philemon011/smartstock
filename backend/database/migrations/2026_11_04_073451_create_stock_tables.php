<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('number', 20)->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('reason', 30);
            $table->date('entry_date');
            $table->foreignUlid('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignUlid('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $table->string('reference', 60)->nullable();
            $table->string('notes', 500)->nullable();
            $table->string('document_path')->nullable();
            $table->string('document_name', 150)->nullable();
            $table->decimal('total_amount', 19, 4)->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->foreignUlid('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignUlid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 300)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number'], 'stock_entries_tenant_number_unique');
            $table->index(['tenant_id', 'status', 'entry_date']);
            $table->index(['tenant_id', 'warehouse_id']);
            $table->index(['tenant_id', 'supplier_id']);
        });

        Schema::create('stock_entry_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('stock_entry_id')->constrained('stock_entries')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->foreignUlid('article_id')->constrained('articles')->restrictOnDelete();
            $table->foreignUlid('packaging_id')->nullable()->constrained('article_packagings')->nullOnDelete();
            $table->boolean('in_packaging')->default(false);
            $table->string('entered_unit', 50);
            $table->decimal('factor', 18, 3)->default(1);
            $table->decimal('entered_quantity', 18, 3);
            $table->decimal('quantity', 18, 3);
            $table->decimal('unit_price', 19, 4);
            $table->decimal('line_total', 19, 4);
            $table->date('expiry_date')->nullable();
            $table->string('lot_number', 60)->nullable();
            $table->decimal('sale_price', 19, 4)->nullable();
            $table->timestamps();

            $table->index(['stock_entry_id', 'position']);
            $table->index(['tenant_id', 'article_id']);
        });

        Schema::create('stock_lots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 20);
            $table->foreignUlid('article_id')->constrained('articles')->restrictOnDelete();
            $table->foreignUlid('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignUlid('stock_entry_line_id')->constrained('stock_entry_lines')->noActionOnDelete();
            $table->string('lot_number', 60)->nullable();
            $table->date('received_date');
            $table->date('expiry_date')->nullable();
            $table->decimal('quantity_initial', 18, 3);
            $table->decimal('quantity_remaining', 18, 3);
            $table->decimal('total_cost', 19, 4);
            $table->decimal('unit_cost', 19, 6);
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'stock_lots_tenant_code_unique');
            $table->index(['tenant_id', 'article_id', 'warehouse_id', 'expiry_date'], 'stock_lots_picking_index');
            $table->index(['tenant_id', 'warehouse_id']);
            $table->index('stock_entry_line_id');
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('type', 30);
            $table->foreignUlid('article_id')->constrained('articles')->restrictOnDelete();
            $table->foreignUlid('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignUlid('lot_id')->constrained('stock_lots')->noActionOnDelete();
            $table->decimal('quantity', 18, 3);
            $table->decimal('unit_cost', 19, 6);
            $table->foreignUlid('stock_entry_id')->nullable()->constrained('stock_entries')->noActionOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'article_id', 'created_at']);
            $table->index(['tenant_id', 'warehouse_id', 'created_at']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE stock_entries
                ADD CONSTRAINT stock_entries_status_valid CHECK (status IN ('draft', 'validated', 'cancelled')),
                ADD CONSTRAINT stock_entries_reason_valid CHECK (reason IN ('purchase', 'customer_return', 'donation', 'opening_stock', 'other')),
                ADD CONSTRAINT stock_entries_validated_has_number CHECK (status = 'draft' OR number IS NOT NULL);

            ALTER TABLE stock_entry_lines
                ADD CONSTRAINT stock_entry_lines_quantities_positive CHECK (entered_quantity > 0 AND quantity > 0 AND factor > 0),
                ADD CONSTRAINT stock_entry_lines_amounts_valid CHECK (unit_price >= 0 AND line_total >= 0);

            ALTER TABLE stock_lots
                ADD CONSTRAINT stock_lots_quantities_valid CHECK (quantity_initial > 0 AND quantity_remaining >= 0 AND quantity_remaining <= quantity_initial),
                ADD CONSTRAINT stock_lots_amounts_valid CHECK (total_cost >= 0 AND unit_cost >= 0);

            ALTER TABLE stock_movements
                ADD CONSTRAINT stock_movements_quantity_not_zero CHECK (quantity <> 0);
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_stock_entries() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'draft' AND EXISTS (SELECT 1 FROM tenants WHERE id = OLD.tenant_id) THEN
                        RAISE EXCEPTION 'Une entrée validée ne peut pas être supprimée : annulez-la.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN OLD;
                END IF;

                IF OLD.status = 'cancelled' AND NEW.status <> 'cancelled' THEN
                    RAISE EXCEPTION 'Une entrée annulée ne peut pas être rouverte.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF OLD.status = 'validated' AND NEW.status = 'draft' THEN
                    RAISE EXCEPTION 'Une entrée validée ne peut pas redevenir un brouillon.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF OLD.status <> 'draft' AND (
                    NEW.warehouse_id <> OLD.warehouse_id
                    OR NEW.entry_date <> OLD.entry_date
                    OR NEW.reason <> OLD.reason
                    OR NEW.supplier_id IS DISTINCT FROM OLD.supplier_id
                    OR NEW.total_amount IS DISTINCT FROM OLD.total_amount
                    OR NEW.number IS DISTINCT FROM OLD.number
                ) THEN
                    RAISE EXCEPTION 'Les informations d''une entrée validée ne peuvent plus être modifiées.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER stock_entries_guard
            BEFORE UPDATE OR DELETE ON stock_entries
            FOR EACH ROW EXECUTE FUNCTION guard_stock_entries();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_stock_entry_lines() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM stock_entries e WHERE e.id = OLD.stock_entry_id AND e.status <> 'draft')
                   AND EXISTS (SELECT 1 FROM tenants WHERE id = OLD.tenant_id) THEN
                    RAISE EXCEPTION 'Les lignes d''une entrée validée ne peuvent plus être modifiées.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER stock_entry_lines_guard
            BEFORE UPDATE OR DELETE ON stock_entry_lines
            FOR EACH ROW EXECUTE FUNCTION guard_stock_entry_lines();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_stock_lots() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF EXISTS (SELECT 1 FROM tenants WHERE id = OLD.tenant_id) THEN
                        RAISE EXCEPTION 'Un lot ne peut pas être supprimé.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN OLD;
                END IF;

                IF NEW.article_id <> OLD.article_id
                   OR NEW.warehouse_id <> OLD.warehouse_id
                   OR NEW.stock_entry_line_id <> OLD.stock_entry_line_id
                   OR NEW.quantity_initial <> OLD.quantity_initial
                   OR NEW.total_cost <> OLD.total_cost
                   OR NEW.unit_cost <> OLD.unit_cost THEN
                    RAISE EXCEPTION 'Les données d''origine d''un lot ne peuvent pas être modifiées.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER stock_lots_guard
            BEFORE UPDATE OR DELETE ON stock_lots
            FOR EACH ROW EXECUTE FUNCTION guard_stock_lots();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_stock_movements() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'UPDATE' OR EXISTS (SELECT 1 FROM tenants WHERE id = OLD.tenant_id) THEN
                    RAISE EXCEPTION 'Le journal des mouvements de stock est immuable.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER stock_movements_guard
            BEFORE UPDATE OR DELETE ON stock_movements
            FOR EACH ROW EXECUTE FUNCTION guard_stock_movements();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_guard ON stock_movements');
        DB::unprepared('DROP FUNCTION IF EXISTS guard_stock_movements()');
        Schema::dropIfExists('stock_movements');

        DB::unprepared('DROP TRIGGER IF EXISTS stock_lots_guard ON stock_lots');
        DB::unprepared('DROP FUNCTION IF EXISTS guard_stock_lots()');
        Schema::dropIfExists('stock_lots');

        DB::unprepared('DROP TRIGGER IF EXISTS stock_entry_lines_guard ON stock_entry_lines');
        DB::unprepared('DROP FUNCTION IF EXISTS guard_stock_entry_lines()');
        Schema::dropIfExists('stock_entry_lines');

        DB::unprepared('DROP TRIGGER IF EXISTS stock_entries_guard ON stock_entries');
        DB::unprepared('DROP FUNCTION IF EXISTS guard_stock_entries()');
        Schema::dropIfExists('stock_entries');
    }
};