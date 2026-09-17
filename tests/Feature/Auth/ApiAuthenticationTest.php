<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_email_and_read_current_profile(): void
    {
        $role = Role::create(['name' => 'super-admin']);
        $permission = Permission::create(['name' => 'authorization.permissions.view']);

        $role->givePermissionTo($permission);

        $user = User::factory()->create([
            'email' => 'owner@example.com',
            'username' => 'owner',
            'password' => 'secret-password',
            'account_status' => 'active',
        ]);
        $user->assignRole($role);

        $login = $this->postJson('/api/v1/auth/login', [
            'login' => 'owner@example.com',
            'password' => 'secret-password',
            'device_name' => 'feature-test',
        ]);

        $login->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.email', 'owner@example.com')
            ->assertJsonPath('user.roles.0', 'super-admin')
            ->assertJsonPath('user.permissions.0', 'authorization.permissions.view');

        $token = $login->json('access_token');

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'owner@example.com');
    }

    public function test_user_can_login_with_username(): void
    {
        User::factory()->create([
            'email' => 'owner@example.com',
            'username' => 'owner',
            'password' => 'secret-password',
            'account_status' => 'active',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'login' => 'owner',
            'password' => 'secret-password',
            'device_name' => 'feature-test',
        ])->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.username', 'owner');
    }


    public function test_inactive_user_cannot_login_even_with_correct_credentials(): void
    {
        User::factory()->create([
            'email' => 'inactive@example.com',
            'username' => 'inactive',
            'password' => 'secret-password',
            'account_status' => 'inactive',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'login' => 'inactive@example.com',
            'password' => 'secret-password',
            'device_name' => 'feature-test',
        ])->assertForbidden()
            ->assertJsonPath('message', "You've been deactivated.");
    }

    public function test_deactivated_authenticated_user_cannot_take_further_actions(): void
    {
        $user = User::factory()->create([
            'account_status' => 'active',
        ]);
        $token = $user->createToken('feature-test')->plainTextToken;

        $user->forceFill(['account_status' => 'inactive'])->save();

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath('message', "You've been deactivated.");
    }
    public function test_login_rejects_split_identifier_fields(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'secret-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['login']);
    }

    public function test_permission_middleware_denies_missing_permission(): void
    {
        $user = User::factory()->create([
            'account_status' => 'active',
        ]);

        $token = $user->createToken('feature-test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/authorization/permissions')
            ->assertForbidden();
    }

    public function test_permission_middleware_allows_user_with_permission(): void
    {
        $permission = Permission::create(['name' => 'authorization.permissions.view']);
        $user = User::factory()->create([
            'account_status' => 'active',
        ]);
        $user->givePermissionTo($permission);

        $token = $user->createToken('feature-test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/authorization/permissions')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'authorization.permissions.view');
    }
}
