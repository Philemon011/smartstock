<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Schema::create('categories', function (Blueprint $table) {
        //     $table->ulid('id')->primary();
        //     $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
        //     $table->foreignUlid('parent_id')->nullable()->constrained('categories')->noActionOnDelete();
        //     $table->string('name', 100);
        //     $table->string('name_key');
        //     $table->string('description', 500)->nullable();
        //     $table->boolean('is_active')->default(true);
        //     $table->timestamps();

        //     $table->index(['tenant_id', 'parent_id']);
        //     $table->index(['tenant_id', 'is_active']);
        // });

        Schema::create('categories', function (Blueprint $table) {
            // Colonne id + clé primaire déclarée explicitement EN PREMIER,
            // pour qu'elle existe avant la clé étrangère auto-référencée parent_id
            $table->ulid('id');
            $table->primary('id');

            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Clé étrangère auto-référencée : nécessite que la PK de categories existe déjà
            $table->foreignUlid('parent_id')->nullable()->constrained('categories')->noActionOnDelete();

            $table->string('name', 100);
            $table->string('name_key');
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'parent_id']);
            $table->index(['tenant_id', 'is_active']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE categories
                ADD CONSTRAINT categories_name_not_blank CHECK (char_length(btrim(name)) >= 2),
                ADD CONSTRAINT categories_not_own_parent CHECK (parent_id IS NULL OR parent_id <> id);

            CREATE UNIQUE INDEX categories_root_name_unique
                ON categories (tenant_id, name_key) WHERE parent_id IS NULL;

            CREATE UNIQUE INDEX categories_child_name_unique
                ON categories (tenant_id, parent_id, name_key) WHERE parent_id IS NOT NULL;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_category_depth() RETURNS trigger AS $$
            DECLARE
                parent_row categories%ROWTYPE;
            BEGIN
                IF NEW.parent_id IS NOT NULL THEN
                    SELECT * INTO parent_row FROM categories WHERE id = NEW.parent_id;

                    IF NOT FOUND OR parent_row.tenant_id <> NEW.tenant_id THEN
                        RAISE EXCEPTION 'La catégorie parente est invalide.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF parent_row.parent_id IS NOT NULL THEN
                        RAISE EXCEPTION 'Une sous-catégorie ne peut pas avoir de sous-catégories.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF TG_OP = 'UPDATE' AND EXISTS (SELECT 1 FROM categories WHERE parent_id = NEW.id) THEN
                        RAISE EXCEPTION 'Une catégorie qui contient des sous-catégories ne peut pas devenir une sous-catégorie.'
                            USING ERRCODE = 'check_violation';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER categories_enforce_depth
            BEFORE INSERT OR UPDATE OF parent_id, tenant_id ON categories
            FOR EACH ROW EXECUTE FUNCTION enforce_category_depth();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS categories_enforce_depth ON categories');
        DB::unprepared('DROP FUNCTION IF EXISTS enforce_category_depth()');
        Schema::dropIfExists('categories');
    }
};
