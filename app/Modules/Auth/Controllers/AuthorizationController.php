<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Services\AuthorizationService;
use Illuminate\Http\JsonResponse;

class AuthorizationController extends Controller
{
    public function __construct(private readonly AuthorizationService $authorizationService) {}

    public function permissions(): JsonResponse
    {
        return response()->json([
            'data' => $this->authorizationService->permissionCatalog(),
        ]);
    }
}
