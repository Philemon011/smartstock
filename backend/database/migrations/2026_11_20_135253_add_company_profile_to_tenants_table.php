<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('email', 254)->nullable()->after('phone');
            $table->string('city', 100)->nullable()->after('address');
            $table->string('website', 200)->nullable()->after('city');
            $table->string('registration_number', 50)->nullable()->after('tax_id');
            $table->string('legal_mentions', 500)->nullable()->after('registration_number');
            $table->string('receipt_footer', 200)->nullable()->after('legal_mentions');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['email', 'city', 'website', 'registration_number', 'legal_mentions', 'receipt_footer']);
        });
    }
};