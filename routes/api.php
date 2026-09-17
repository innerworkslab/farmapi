<?php

use App\Modules\Auth\Controllers\AuthController;
use App\Modules\Auth\Controllers\AuthorizationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::get('/authorization/permissions', [AuthorizationController::class, 'permissions'])
            ->middleware('permission:authorization.permissions.view');
    });
});
