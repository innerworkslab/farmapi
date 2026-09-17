<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Auth\Repositories\UserRepository;
use App\Modules\Auth\Resources\AuthenticatedUserResource;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AuthService
{
    public function __construct(private readonly UserRepository $users) {}

    /**
     * @param  array{login: string, password: string, device_name?: string}  $credentials
     * @return array<string, mixed>
     */
    public function login(array $credentials): array
    {
        $identifier = $credentials['login'];
        $column = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        $user = $this->users->findForLogin($identifier, $column);

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            if ($user) {
                $this->users->recordFailedLogin($user);
            }

            throw ValidationException::withMessages([
                'login' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->account_status !== 'active') {
            throw new HttpException(403, "You've been deactivated.");
        }

        $this->users->recordSuccessfulLogin($user);
        $token = $user->createToken($credentials['device_name'] ?? 'api')->plainTextToken;

        return [
            'token_type' => 'Bearer',
            'access_token' => $token,
            'user' => new AuthenticatedUserResource($user->loadMissing('roles.permissions', 'permissions')),
        ];
    }

    public function logout(User $user): void
    {
        $token = $user->currentAccessToken();

        if (! $token) {
            throw new AuthenticationException;
        }

        $token->delete();
    }
}
