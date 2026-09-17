<?php

namespace App\Modules\Setup\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'username' => $this->username,
            'account_status' => $this->account_status,
            'two_factor_enabled' => $this->two_factor_enabled,
            'last_login_at' => $this->last_login_at?->toISOString(),
            'password_changed_at' => $this->password_changed_at?->toISOString(),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')->values()),
            'branches' => $this->whenLoaded('branches', fn () => $this->branches->map(fn ($branch): array => [
                'id' => $branch->id,
                'code' => $branch->code,
                'name' => $branch->name,
                'status' => $branch->status,
            ])->values()),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
