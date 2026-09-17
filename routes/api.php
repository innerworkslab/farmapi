<?php

use App\Modules\Auth\Controllers\AuthController;
use App\Modules\Auth\Controllers\AuthorizationController;
use App\Modules\Setup\Controllers\ActivityLogController;
use App\Modules\Setup\Controllers\AdminController;
use App\Modules\Setup\Controllers\BranchController;
use App\Modules\Setup\Controllers\RoleController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/login', [AuthController::class, 'login']);

    Route::middleware(['auth:sanctum', 'active_user'])->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::get('/authorization/permissions', [AuthorizationController::class, 'permissions'])
            ->middleware('permission:authorization.permissions.view');

        Route::prefix('setup')->group(function (): void {
            Route::get('branches', [BranchController::class, 'index'])->middleware('permission:setup.branches.view');
            Route::post('branches', [BranchController::class, 'store'])->middleware('permission:setup.branches.create');
            Route::get('branches/{branch}', [BranchController::class, 'show'])->middleware('permission:setup.branches.view');
            Route::post('branches/{branch}', [BranchController::class, 'update'])->middleware('permission:setup.branches.update');
            Route::post('branches/{branch}/toggle-status', [BranchController::class, 'toggleStatus'])->middleware('permission:setup.branches.update');
            Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->middleware('permission:setup.branches.delete');

            Route::get('roles', [RoleController::class, 'index'])->middleware('permission:setup.roles.view');
            Route::post('roles', [RoleController::class, 'store'])->middleware('permission:setup.roles.create');
            Route::get('roles/{role}', [RoleController::class, 'show'])->middleware('permission:setup.roles.view');
            Route::post('roles/{role}', [RoleController::class, 'update'])->middleware('permission:setup.roles.update');
            Route::post('roles/{role}/toggle-status', [RoleController::class, 'toggleStatus'])->middleware('permission:setup.roles.update');
            Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:setup.roles.delete');

            Route::get('admins', [AdminController::class, 'index'])->middleware('permission:setup.admins.view');
            Route::post('admins', [AdminController::class, 'store'])->middleware('permission:setup.admins.create');
            Route::get('admins/{admin}', [AdminController::class, 'show'])->middleware('permission:setup.admins.view');
            Route::post('admins/{admin}', [AdminController::class, 'update'])->middleware('permission:setup.admins.update');
            Route::delete('admins/{admin}', [AdminController::class, 'destroy'])->middleware('permission:setup.admins.delete');

            Route::get('activity-logs', [ActivityLogController::class, 'index'])->middleware('permission:setup.audit.view');
            Route::get('activity-logs/{activityLog}', [ActivityLogController::class, 'show'])->middleware('permission:setup.audit.view');
        });
    });
});
