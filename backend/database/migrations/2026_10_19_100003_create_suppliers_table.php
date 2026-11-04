<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('name_key');
            $table->string('contact_name', 100)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('notes', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name_key'], 'suppliers_tenant_name_unique');
            $table->index(['tenant_id', 'is_active']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE suppliers
                ADD CONSTRAINT suppliers_name_not_blank CHECK (char_length(btrim(name)) >= 2);
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};