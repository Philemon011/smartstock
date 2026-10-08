<?php

namespace Tests\Feature;

use App\Actions\Tenants\RegisterTenant;
use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantMembershipTest extends TestCase
{
    /**
     * @return array{tenant: Tenant, ownerMembership: TenantUser}
     */
    private function createTenantWithOwner(): array
    {
        $result = app(RegisterTenant::class)->handle([
            'company_name' => 'Boutique Test',
            'country' => 'BJ',
            'currency' => 'XOF',
            'name' => 'Jean Dupont',
            'email' => 'jean@example.com',
            'password' => 'mot-de-passe-test-123',
        ]);

        return [
            'tenant' => $result['tenant'],
            'ownerMembership' => $result['tenant']->memberships()->firstOrFail(),
        ];
    }

    private function assertRejected(callable $change): void
    {
        try {
            DB::transaction($change);
            $this->fail('La modification aurait dû être refusée par la base.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('au moins un propriétaire actif', $e->getMessage());
        }
    }

    public function test_the_last_active_owner_cannot_be_demoted_suspended_or_removed(): void
    {
        ['ownerMembership' => $owner] = $this->createTenantWithOwner();
        $id = $owner->getKey();

        $this->assertRejected(fn () => TenantUser::whereKey($id)->update(['role' => 'admin']));
        $this->assertRejected(fn () => TenantUser::whereKey($id)->update(['status' => 'suspended']));
        $this->assertRejected(fn () => TenantUser::whereKey($id)->delete());
    }

    public function test_an_owner_can_be_demoted_once_another_owner_exists(): void
    {
        ['tenant' => $tenant, 'ownerMembership' => $first] = $this->createTenantWithOwner();

        $second = User::create([
            'name' => 'Second Owner',
            'email' => 'second@example.com',
            'password' => 'mot-de-passe-test-123',
        ]);

        $secondMembership = $tenant->memberships()->create([
            'user_id' => $second->id,
            'role' => TenantRole::Admin,
        ]);

        TenantUser::whereKey($secondMembership->getKey())->update(['role' => 'owner']);

        $this->assertSame(
            1,
            TenantUser::whereKey($first->getKey())->update(['role' => 'admin']),
        );
    }

    public function test_deleting_a_tenant_removes_its_memberships_without_being_blocked(): void
    {
        ['tenant' => $tenant] = $this->createTenantWithOwner();

        $tenant->delete();

        $this->assertSame(0, TenantUser::count());
    }
}