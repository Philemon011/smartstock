<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('name_key');
            $table->string('phone', 30)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('notes', 500)->nullable();
            $table->decimal('credit_limit', 19, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'name_key']);
            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'phone']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE customers
                ADD CONSTRAINT customers_name_not_blank CHECK (char_length(btrim(name)) >= 2),
                ADD CONSTRAINT customers_credit_limit_check CHECK (credit_limit IS NULL OR credit_limit >= 0);
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};