<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 20);
            $table->string('status', 20)->default('validated');
            $table->date('sale_date');
            $table->foreignUlid('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('notes', 500)->nullable();
            $table->decimal('subtotal', 19, 4);
            $table->decimal('discount_total', 19, 4)->default(0);
            $table->decimal('total', 19, 4);
            $table->decimal('amount_paid', 19, 4)->default(0);
            $table->decimal('cost_total', 19, 4)->default(0);
            $table->string('payment_status', 20);
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignUlid('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancel_reason', 300)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'sale_date']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'payment_status']);
            $table->index('customer_id');
            $table->index('warehouse_id');
        });

        DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_status_check CHECK (status IN ('validated', 'cancelled'))");
        DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_payment_status_check CHECK (payment_status IN ('paid', 'partial', 'unpaid'))");
        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_amounts_check CHECK (subtotal >= 0 AND discount_total >= 0 AND total > 0 AND cost_total >= 0 AND amount_paid >= 0 AND amount_paid <= total AND subtotal - discount_total = total)');

        Schema::create('sale_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->foreignUlid('article_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('packaging_id')->nullable()->constrained('article_packagings')->nullOnDelete();
            $table->boolean('in_packaging')->default(false);
            $table->string('entered_unit', 50);
            $table->decimal('factor', 18, 3);
            $table->decimal('entered_quantity', 18, 3);
            $table->decimal('quantity', 18, 3);
            $table->decimal('list_price', 19, 4);
            $table->decimal('unit_price', 19, 4);
            $table->string('discount_type', 10)->nullable();
            $table->decimal('discount_value', 19, 4)->nullable();
            $table->decimal('discount_amount', 19, 4)->default(0);
            $table->decimal('gross_total', 19, 4);
            $table->decimal('line_total', 19, 4);
            $table->decimal('cost_total', 19, 4)->default(0);
            $table->timestamps();

            $table->index('sale_id');
            $table->index('article_id');
        });

        DB::statement('ALTER TABLE sale_lines ADD CONSTRAINT sale_lines_amounts_check CHECK (factor > 0 AND entered_quantity > 0 AND quantity > 0 AND list_price >= 0 AND unit_price >= 0 AND discount_amount >= 0 AND discount_amount <= gross_total AND line_total = gross_total - discount_amount AND cost_total >= 0)');
        DB::statement("ALTER TABLE sale_lines ADD CONSTRAINT sale_lines_discount_check CHECK ((discount_type IS NULL AND discount_value IS NULL) OR (discount_type IN ('percent', 'amount') AND discount_value > 0 AND (discount_type <> 'percent' OR discount_value <= 100)))");

        Schema::create('sale_allocations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('sale_line_id')->constrained('sale_lines')->cascadeOnDelete();
            $table->foreignUlid('lot_id')->constrained('stock_lots')->noActionOnDelete();
            $table->decimal('quantity', 18, 3);
            $table->decimal('unit_cost', 19, 6);
            $table->decimal('cost', 19, 4);
            $table->timestamp('created_at')->useCurrent();

            $table->index('sale_line_id');
            $table->index('lot_id');
        });

        DB::statement('ALTER TABLE sale_allocations ADD CONSTRAINT sale_allocations_check CHECK (quantity > 0 AND unit_cost >= 0 AND cost >= 0)');

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->string('method', 20);
            $table->decimal('amount', 19, 4);
            $table->decimal('tendered', 19, 4)->nullable();
            $table->decimal('change_given', 19, 4)->default(0);
            $table->string('reference', 100)->nullable();
            $table->timestamp('paid_at');
            $table->string('status', 20)->default('valid');
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignUlid('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 300)->nullable();
            $table->timestamps();

            $table->index('sale_id');
            $table->index(['tenant_id', 'paid_at']);
        });

        DB::statement("ALTER TABLE sale_payments ADD CONSTRAINT sale_payments_method_check CHECK (method IN ('cash', 'mtn_momo', 'moov_money', 'card', 'bank_transfer'))");
        DB::statement("ALTER TABLE sale_payments ADD CONSTRAINT sale_payments_status_check CHECK (status IN ('valid', 'voided') AND ((status = 'voided') = (voided_at IS NOT NULL)))");
        DB::statement("ALTER TABLE sale_payments ADD CONSTRAINT sale_payments_amounts_check CHECK (amount > 0 AND change_given >= 0 AND ((tendered IS NULL AND change_given = 0) OR (tendered IS NOT NULL AND method = 'cash' AND change_given = tendered - amount)))");

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignUlid('sale_id')->nullable()->constrained('sales')->noActionOnDelete();
            $table->index('sale_id');
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_sales() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF EXISTS (SELECT 1 FROM tenants WHERE id = OLD.tenant_id) THEN
            RAISE EXCEPTION 'Une vente ne peut pas être supprimée : annulez-la.' USING ERRCODE = 'check_violation';
        END IF;
        RETURN OLD;
    END IF;

    IF OLD.status = 'cancelled' THEN
        RAISE EXCEPTION 'Une vente annulée ne peut plus être modifiée.' USING ERRCODE = 'check_violation';
    END IF;

    IF NEW.number IS DISTINCT FROM OLD.number
        OR NEW.sale_date <> OLD.sale_date
        OR NEW.warehouse_id <> OLD.warehouse_id
        OR NEW.customer_id IS DISTINCT FROM OLD.customer_id
        OR NEW.subtotal <> OLD.subtotal
        OR NEW.discount_total <> OLD.discount_total
        OR NEW.total <> OLD.total
        OR NEW.cost_total <> OLD.cost_total
    THEN
        RAISE EXCEPTION 'Le contenu d''une vente validée ne peut pas être modifié.' USING ERRCODE = 'check_violation';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER sales_guard BEFORE UPDATE OR DELETE ON sales
FOR EACH ROW EXECUTE FUNCTION guard_sales();

CREATE OR REPLACE FUNCTION guard_sale_children() RETURNS trigger AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM tenants WHERE id = OLD.tenant_id) THEN
        RAISE EXCEPTION 'Le détail d''une vente ne peut pas être modifié.' USING ERRCODE = 'check_violation';
    END IF;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER sale_lines_guard BEFORE UPDATE OR DELETE ON sale_lines
FOR EACH ROW EXECUTE FUNCTION guard_sale_children();

CREATE TRIGGER sale_allocations_guard BEFORE UPDATE OR DELETE ON sale_allocations
FOR EACH ROW EXECUTE FUNCTION guard_sale_children();

CREATE OR REPLACE FUNCTION guard_sale_payments() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF EXISTS (SELECT 1 FROM tenants WHERE id = OLD.tenant_id) THEN
            RAISE EXCEPTION 'Un paiement ne peut pas être supprimé : annulez-le.' USING ERRCODE = 'check_violation';
        END IF;
        RETURN OLD;
    END IF;

    IF OLD.status = 'voided' THEN
        RAISE EXCEPTION 'Un paiement annulé ne peut plus être modifié.' USING ERRCODE = 'check_violation';
    END IF;

    IF NEW.status <> 'voided'
        OR NEW.sale_id <> OLD.sale_id
        OR NEW.method <> OLD.method
        OR NEW.amount <> OLD.amount
        OR NEW.tendered IS DISTINCT FROM OLD.tendered
        OR NEW.change_given <> OLD.change_given
        OR NEW.reference IS DISTINCT FROM OLD.reference
        OR NEW.paid_at <> OLD.paid_at
    THEN
        RAISE EXCEPTION 'Un paiement ne peut pas être modifié : annulez-le.' USING ERRCODE = 'check_violation';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER sale_payments_guard BEFORE UPDATE OR DELETE ON sale_payments
FOR EACH ROW EXECUTE FUNCTION guard_sale_payments();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS sale_payments_guard ON sale_payments;
DROP TRIGGER IF EXISTS sale_allocations_guard ON sale_allocations;
DROP TRIGGER IF EXISTS sale_lines_guard ON sale_lines;
DROP TRIGGER IF EXISTS sales_guard ON sales;
DROP FUNCTION IF EXISTS guard_sale_payments();
DROP FUNCTION IF EXISTS guard_sale_children();
DROP FUNCTION IF EXISTS guard_sales();
SQL);

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sale_id');
        });

        Schema::dropIfExists('sale_payments');
        Schema::dropIfExists('sale_allocations');
        Schema::dropIfExists('sale_lines');
        Schema::dropIfExists('sales');
    }
};