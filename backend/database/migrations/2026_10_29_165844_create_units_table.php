<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('name_key');
            $table->string('symbol', 10);
            $table->string('symbol_key', 40);
            $table->boolean('allows_decimals')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name_key'], 'units_tenant_name_unique');
            $table->unique(['tenant_id', 'symbol_key'], 'units_tenant_symbol_unique');
            $table->index(['tenant_id', 'is_active']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE units
                ADD CONSTRAINT units_name_not_blank CHECK (char_length(btrim(name)) >= 2),
                ADD CONSTRAINT units_symbol_not_blank CHECK (char_length(btrim(symbol)) >= 1);
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};