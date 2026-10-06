<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 120);
            $table->char('country', 2);
            $table->char('currency', 3);
            $table->string('timezone', 64)->default('UTC');
            $table->string('status', 20)->default('trial');
            $table->timestamp('trial_ends_at')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('address')->nullable();
            $table->string('tax_id', 50)->nullable();
            $table->string('logo_path')->nullable();
            $table->timestamps();

            $table->index(['status', 'trial_ends_at']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE tenants
                ADD CONSTRAINT tenants_name_not_blank CHECK (char_length(btrim(name)) >= 2),
                ADD CONSTRAINT tenants_country_format CHECK (country ~ '^[A-Z]{2}$'),
                ADD CONSTRAINT tenants_currency_format CHECK (currency ~ '^[A-Z]{3}$'),
                ADD CONSTRAINT tenants_status_valid CHECK (status IN ('trial', 'active', 'expired', 'suspended'));
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};