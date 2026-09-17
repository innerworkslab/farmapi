<?php

namespace App\Modules\Auth\Repositories;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class UserRepository
{
    public function findForLogin(string $identifier, string $column): ?User
    {
        return User::query()
            ->where($column, $identifier)
            ->first();
    }

    public function recordFailedLogin(User $user): void
    {
        $failedLoginCount = $user->failed_login_count + 1;

        $user->forceFill([
            'failed_login_count' => $failedLoginCount,
            'account_status' => $failedLoginCount >= 5 ? 'locked' : $user->account_status,
        ])->save();
    }

    public function recordSuccessfulLogin(User $user): void
    {
        $user->forceFill([
            'failed_login_count' => 0,
            'last_login_at' => now(),
        ])->save();
    }

    public function queryWithAuthorization(): Builder
    {
        return User::query()->with('roles.permissions', 'permissions');
    }
}
