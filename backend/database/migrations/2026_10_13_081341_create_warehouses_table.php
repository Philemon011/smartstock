<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('name_key');
            $table->string('code', 30)->nullable();
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('phone', 30)->nullable();
            $table->boolean('is_main')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name_key'], 'warehouses_tenant_name_unique');
            $table->unique(['tenant_id', 'code'], 'warehouses_tenant_code_unique');
            $table->index(['tenant_id', 'is_active']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE warehouses
                ADD CONSTRAINT warehouses_name_not_blank CHECK (char_length(btrim(name)) >= 2),
                ADD CONSTRAINT warehouses_main_is_active CHECK (NOT is_main OR is_active);

            CREATE UNIQUE INDEX warehouses_one_main_per_tenant ON warehouses (tenant_id) WHERE is_main;
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};