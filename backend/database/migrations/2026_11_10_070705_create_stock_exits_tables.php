<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_exits', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 20)->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('reason', 30);
            $table->date('exit_date');
            $table->foreignUlid('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('supplier_id')->nullable()->constrained()->restrictOnDelete();
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
            $table->index(['tenant_id', 'exit_date']);
            $table->index('warehouse_id');
            $table->index('supplier_id');
        });

        DB::statement("ALTER TABLE stock_exits ADD CONSTRAINT stock_exits_status_check CHECK (status IN ('draft', 'validated', 'cancelled'))");
        DB::statement("ALTER TABLE stock_exits ADD CONSTRAINT stock_exits_reason_check CHECK (reason IN ('loss', 'breakage', 'expiry', 'internal_use', 'donation', 'supplier_return', 'other'))");
        DB::statement("ALTER TABLE stock_exits ADD CONSTRAINT stock_exits_number_check CHECK (status = 'draft' OR number IS NOT NULL)");

        Schema::create('stock_exit_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('stock_exit_id')->constrained('stock_exits')->cascadeOnDelete();
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

            $table->index('stock_exit_id');
            $table->index('article_id');
            $table->index('lot_id');
        });

        DB::statement('ALTER TABLE stock_exit_lines ADD CONSTRAINT stock_exit_lines_positive_check CHECK (factor > 0 AND entered_quantity > 0 AND quantity > 0)');

        Schema::create('stock_exit_allocations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('stock_exit_line_id')->constrained('stock_exit_lines')->cascadeOnDelete();
            $table->foreignUlid('lot_id')->constrained('stock_lots')->noActionOnDelete();
            $table->decimal('quantity', 18, 3);
            $table->decimal('unit_cost', 19, 6);
            $table->decimal('value', 19, 4);
            $table->timestamp('created_at')->useCurrent();

            $table->index('stock_exit_line_id');
            $table->index('lot_id');
        });

        DB::statement('ALTER TABLE stock_exit_allocations ADD CONSTRAINT stock_exit_allocations_check CHECK (quantity > 0 AND unit_cost >= 0 AND value >= 0)');

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignUlid('stock_exit_id')->nullable()->constrained('stock_exits')->noActionOnDelete();
            $table->index('stock_exit_id');
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_stock_exits() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.status <> 'draft' AND EXISTS (SELECT 1 FROM tenants WHERE id = OLD.tenant_id) THEN
            RAISE EXCEPTION 'Une sortie validée ne peut pas être supprimée : annulez-la.' USING ERRCODE = 'check_violation';
        END IF;
        RETURN OLD;
    END IF;

    IF OLD.status = 'cancelled' AND NEW.status <> 'cancelled' THEN
        RAISE EXCEPTION 'Une sortie annulée ne peut plus être modifiée.' USING ERRCODE = 'check_violation';
    END IF;

    IF OLD.status = 'validated' AND NEW.status = 'draft' THEN
        RAISE EXCEPTION 'Une sortie validée ne peut pas redevenir un brouillon.' USING ERRCODE = 'check_violation';
    END IF;

    IF OLD.status <> 'draft' AND (
        NEW.warehouse_id <> OLD.warehouse_id
        OR NEW.exit_date <> OLD.exit_date
        OR NEW.reason <> OLD.reason
        OR NEW.supplier_id IS DISTINCT FROM OLD.supplier_id
        OR NEW.total_cost IS DISTINCT FROM OLD.total_cost
        OR NEW.number IS DISTINCT FROM OLD.number
    ) THEN
        RAISE EXCEPTION 'Une sortie validée ne peut pas être modifiée.' USING ERRCODE = 'check_violation';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER stock_exits_guard BEFORE UPDATE OR DELETE ON stock_exits
FOR EACH ROW EXECUTE FUNCTION guard_stock_exits();

CREATE OR REPLACE FUNCTION guard_stock_exit_lines() RETURNS trigger AS $$
DECLARE
    parent_status text;
BEGIN
    IF TG_OP = 'INSERT' THEN
        SELECT status INTO parent_status FROM stock_exits WHERE id = NEW.stock_exit_id;
        IF parent_status <> 'draft' THEN
            RAISE EXCEPTION 'Les lignes d''une sortie validée ne peuvent pas être modifiées.' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END IF;

    SELECT status INTO parent_status FROM stock_exits WHERE id = OLD.stock_exit_id;

    IF parent_status IS NOT NULL AND parent_status <> 'draft' AND EXISTS (SELECT 1 FROM tenants WHERE id = OLD.tenant_id) THEN
        RAISE EXCEPTION 'Les lignes d''une sortie validée ne peuvent pas être modifiées.' USING ERRCODE = 'check_violation';
    END IF;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER stock_exit_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON stock_exit_lines
FOR EACH ROW EXECUTE FUNCTION guard_stock_exit_lines();

CREATE OR REPLACE FUNCTION guard_stock_exit_allocations() RETURNS trigger AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM tenants WHERE id = OLD.tenant_id) THEN
        RAISE EXCEPTION 'Le détail des lots d''une sortie ne peut pas être modifié.' USING ERRCODE = 'check_violation';
    END IF;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER stock_exit_allocations_guard BEFORE UPDATE OR DELETE ON stock_exit_allocations
FOR EACH ROW EXECUTE FUNCTION guard_stock_exit_allocations();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS stock_exit_allocations_guard ON stock_exit_allocations;
DROP TRIGGER IF EXISTS stock_exit_lines_guard ON stock_exit_lines;
DROP TRIGGER IF EXISTS stock_exits_guard ON stock_exits;
DROP FUNCTION IF EXISTS guard_stock_exit_allocations();
DROP FUNCTION IF EXISTS guard_stock_exit_lines();
DROP FUNCTION IF EXISTS guard_stock_exits();
SQL);

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_exit_id');
        });

        Schema::dropIfExists('stock_exit_allocations');
        Schema::dropIfExists('stock_exit_lines');
        Schema::dropIfExists('stock_exits');
    }
};