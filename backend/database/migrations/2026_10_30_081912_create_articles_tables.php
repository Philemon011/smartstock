<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sequences', function (Blueprint $table) {
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 40);
            $table->unsignedBigInteger('last_value')->default(0);

            $table->primary(['tenant_id', 'name']);
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('barcode', 32)->nullable();
            $table->string('name', 150);
            $table->string('name_key');
            $table->string('description', 500)->nullable();
            $table->foreignUlid('category_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->foreignUlid('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('sale_price', 19, 4)->default(0);
            $table->decimal('min_stock', 18, 3)->default(0);
            $table->boolean('track_expiry')->default(false);
            $table->unsignedSmallInteger('expiry_alert_days')->default(30);
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'articles_tenant_code_unique');
            $table->unique(['tenant_id', 'name_key'], 'articles_tenant_name_unique');
            $table->index(['tenant_id', 'category_id']);
            $table->index(['tenant_id', 'unit_id']);
            $table->index(['tenant_id', 'is_active']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE articles
                ADD CONSTRAINT articles_name_not_blank CHECK (char_length(btrim(name)) >= 2),
                ADD CONSTRAINT articles_sale_price_positive CHECK (sale_price >= 0),
                ADD CONSTRAINT articles_min_stock_positive CHECK (min_stock >= 0),
                ADD CONSTRAINT articles_expiry_alert_days_range CHECK (expiry_alert_days BETWEEN 1 AND 365);

            CREATE UNIQUE INDEX articles_tenant_barcode_unique
                ON articles (tenant_id, barcode) WHERE barcode IS NOT NULL;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_article_same_tenant() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM units WHERE id = NEW.unit_id AND tenant_id = NEW.tenant_id) THEN
                    RAISE EXCEPTION 'L''unité de l''article appartient à une autre entreprise.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.category_id IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM categories WHERE id = NEW.category_id AND tenant_id = NEW.tenant_id) THEN
                    RAISE EXCEPTION 'La catégorie de l''article appartient à une autre entreprise.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER articles_same_tenant
            BEFORE INSERT OR UPDATE OF unit_id, category_id, tenant_id ON articles
            FOR EACH ROW EXECUTE FUNCTION enforce_article_same_tenant();
        SQL);

        Schema::create('article_packagings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('article_id')->constrained('articles')->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('name_key');
            $table->decimal('quantity', 18, 3);
            $table->timestamps();

            $table->unique(['article_id', 'name_key'], 'article_packagings_article_name_unique');
        });

        DB::unprepared('ALTER TABLE article_packagings ADD CONSTRAINT article_packagings_quantity_positive CHECK (quantity > 0)');

        Schema::create('article_price_changes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('article_id')->constrained('articles')->cascadeOnDelete();
            $table->decimal('old_price', 19, 4)->nullable();
            $table->decimal('new_price', 19, 4);
            $table->foreignUlid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['article_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_price_changes');
        Schema::dropIfExists('article_packagings');
        DB::unprepared('DROP TRIGGER IF EXISTS articles_same_tenant ON articles');
        DB::unprepared('DROP FUNCTION IF EXISTS enforce_article_same_tenant()');
        Schema::dropIfExists('articles');
        Schema::dropIfExists('sequences');
    }
};