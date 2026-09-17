<?php

namespace Tests\Feature\Setup;

use App\Models\User;
use App\Modules\Setup\Models\Branch;
use App\Modules\Setup\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CoreFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_crud_branch_with_soft_delete_and_status_toggle(): void
    {
        $this->actingWithPermissions([
            'setup.branches.view',
            'setup.branches.create',
            'setup.branches.update',
            'setup.branches.delete',
        ]);

        $create = $this->postJson('/api/v1/setup/branches', [
            'code' => 'BR-TST',
            'name' => 'Test Branch',
            'phone_number' => '+95 9 123456789',
            'address' => 'Demo address',
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.code', 'BR-TST')
            ->assertJsonPath('data.name', 'Test Branch')
            ->assertJsonPath('data.status', 'active');

        $branchId = $create->json('data.id');

        $this->getJson('/api/v1/setup/branches?search=Test')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'BR-TST');

        $this->postJson("/api/v1/setup/branches/{$branchId}", [
            'code' => 'BR-TST',
            'name' => 'Updated Branch',
            'phone_number' => '+95 9 123456789',
            'address' => 'Demo address',
            'status' => 'inactive',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Updated Branch')
            ->assertJsonPath('data.status', 'inactive');

        $this->postJson("/api/v1/setup/branches/{$branchId}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->deleteJson("/api/v1/setup/branches/{$branchId}")
            ->assertOk()
            ->assertJsonPath('message', 'Branch deleted successfully.');

        $this->assertSoftDeleted('branches', ['id' => $branchId]);
    }

    public function test_role_crud_syncs_permissions_with_soft_delete_and_status_toggle(): void
    {
        $this->actingWithPermissions([
            'setup.roles.view',
            'setup.roles.create',
            'setup.roles.update',
            'setup.roles.delete',
        ]);
        Permission::findOrCreate('setup.branches.view', 'web');
        $branch = Branch::query()->create([
            'code' => 'BR-ROL',
            'name' => 'Role Branch',
            'status' => 'active',
        ]);

        $create = $this->postJson('/api/v1/setup/roles', [
            'name' => 'branch-viewer',
            'branch_id' => $branch->id,
            'permissions' => ['setup.branches.view'],
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.name', 'branch-viewer')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.permissions.0', 'setup.branches.view');

        $roleId = $create->json('data.id');

        $this->postJson("/api/v1/setup/roles/{$roleId}", [
            'name' => 'branch-viewer-updated',
            'branch_id' => $branch->id,
            'status' => 'inactive',
            'permissions' => [],
        ])->assertOk()
            ->assertJsonPath('data.name', 'branch-viewer-updated')
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.permissions', []);

        $this->postJson("/api/v1/setup/roles/{$roleId}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->deleteJson("/api/v1/setup/roles/{$roleId}")
            ->assertOk()
            ->assertJsonPath('message', 'Role deleted successfully.');

        $this->assertSoftDeleted('roles', ['id' => $roleId]);
    }

    public function test_admin_crud_assigns_roles_and_branches_with_soft_delete(): void
    {
        $this->actingWithPermissions([
            'setup.admins.view',
            'setup.admins.create',
            'setup.admins.update',
            'setup.admins.delete',
        ]);
        $branch = Branch::query()->create([
            'code' => 'BR-ADM',
            'name' => 'Admin Branch',
            'status' => 'active',
        ]);
        Role::findOrCreate('setup-admin', 'web');

        $create = $this->postJson('/api/v1/setup/admins', [
            'name' => 'Setup Admin',
            'email' => 'setup-admin@example.com',
            'username' => 'setup-admin',
            'password' => 'secret-password',
            'account_status' => 'active',
            'two_factor_enabled' => false,
            'roles' => ['setup-admin'],
            'branch_ids' => [$branch->id],
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.email', 'setup-admin@example.com')
            ->assertJsonPath('data.roles.0', 'setup-admin')
            ->assertJsonPath('data.branches.0.id', $branch->id);

        $adminId = $create->json('data.id');

        $this->deleteJson("/api/v1/setup/admins/{$adminId}")
            ->assertOk()
            ->assertJsonPath('message', 'Admin deleted successfully.');

        $this->assertSoftDeleted('users', ['id' => $adminId]);
    }

    public function test_setup_permission_is_required(): void
    {
        Sanctum::actingAs(User::factory()->create(['account_status' => 'active']));

        $this->getJson('/api/v1/setup/branches')
            ->assertForbidden();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function actingWithPermissions(array $permissions): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user = User::factory()->create(['account_status' => 'active']);
        $user->givePermissionTo($permissions);
        Sanctum::actingAs($user);

        return $user;
    }
}