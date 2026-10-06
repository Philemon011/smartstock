<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_user', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 30);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
            $table->index('user_id');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE tenant_user
                ADD CONSTRAINT tenant_user_role_valid
                    CHECK (role IN ('owner', 'admin', 'stock_manager', 'seller', 'viewer')),
                ADD CONSTRAINT tenant_user_status_valid
                    CHECK (status IN ('active', 'suspended'));
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_tenant_has_owner() RETURNS trigger AS $$
            DECLARE
                other_owners integer;
            BEGIN
                IF OLD.role = 'owner' AND OLD.status = 'active'
                   AND (
                        TG_OP = 'DELETE'
                        OR NEW.role <> 'owner'
                        OR NEW.status <> 'active'
                        OR NEW.tenant_id <> OLD.tenant_id
                   )
                   AND EXISTS (SELECT 1 FROM tenants WHERE id = OLD.tenant_id)
                THEN
                    SELECT count(*) INTO other_owners
                    FROM (
                        SELECT 1
                        FROM tenant_user
                        WHERE tenant_id = OLD.tenant_id
                          AND role = 'owner'
                          AND status = 'active'
                          AND id <> OLD.id
                        FOR UPDATE
                    ) AS remaining_owners;

                    IF other_owners = 0 THEN
                        RAISE EXCEPTION 'Une entreprise doit toujours avoir au moins un propriétaire actif.'
                            USING ERRCODE = 'check_violation';
                    END IF;
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER tenant_user_keep_one_owner
            BEFORE UPDATE OR DELETE ON tenant_user
            FOR EACH ROW EXECUTE FUNCTION enforce_tenant_has_owner();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS tenant_user_keep_one_owner ON tenant_user');
        DB::unprepared('DROP FUNCTION IF EXISTS enforce_tenant_has_owner()');
        Schema::dropIfExists('tenant_user');
    }
};