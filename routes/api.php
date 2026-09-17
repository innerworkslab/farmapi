<?php

use App\Modules\Auth\Controllers\AuthController;
use App\Modules\Auth\Controllers\AuthorizationController;
use App\Modules\Setup\Controllers\ActivityLogController;
use App\Modules\Setup\Controllers\AdminController;
use App\Modules\Setup\Controllers\BranchController;
use App\Modules\Setup\Controllers\RoleController;
use App\Modules\Setup\Controllers\CustomerController;
use App\Modules\Setup\Controllers\SupplierController;
use App\Modules\Setup\Controllers\UomController;
use App\Modules\Setup\Controllers\InventoryController;
use App\Modules\Setup\Controllers\FoodController;
use App\Modules\Setup\Controllers\MedicineController;
use App\Modules\Setup\Controllers\AnimalController;
use App\Modules\Setup\Controllers\EquipmentController;
use App\Modules\Setup\Controllers\FarmInformationController;
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


            Route::get('customers', [CustomerController::class, 'index'])->middleware('permission:setup.customers.view');
            Route::post('customers', [CustomerController::class, 'store'])->middleware('permission:setup.customers.create');
            Route::get('customers/{customer}', [CustomerController::class, 'show'])->middleware('permission:setup.customers.view');
            Route::post('customers/{customer}', [CustomerController::class, 'update'])->middleware('permission:setup.customers.update');
            Route::post('customers/{customer}/toggle-status', [CustomerController::class, 'toggleStatus'])->middleware('permission:setup.customers.update');
            Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->middleware('permission:setup.customers.delete');

            Route::get('suppliers', [SupplierController::class, 'index'])->middleware('permission:setup.suppliers.view');
            Route::post('suppliers', [SupplierController::class, 'store'])->middleware('permission:setup.suppliers.create');
            Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->middleware('permission:setup.suppliers.view');
            Route::post('suppliers/{supplier}', [SupplierController::class, 'update'])->middleware('permission:setup.suppliers.update');
            Route::post('suppliers/{supplier}/toggle-status', [SupplierController::class, 'toggleStatus'])->middleware('permission:setup.suppliers.update');
            Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])->middleware('permission:setup.suppliers.delete');

            Route::get('uoms', [UomController::class, 'index'])->middleware('permission:setup.uoms.view');
            Route::post('uoms', [UomController::class, 'store'])->middleware('permission:setup.uoms.create');
            Route::get('uoms/{uom}', [UomController::class, 'show'])->middleware('permission:setup.uoms.view');
            Route::post('uoms/{uom}', [UomController::class, 'update'])->middleware('permission:setup.uoms.update');
            Route::post('uoms/{uom}/toggle-status', [UomController::class, 'toggleStatus'])->middleware('permission:setup.uoms.update');
            Route::delete('uoms/{uom}', [UomController::class, 'destroy'])->middleware('permission:setup.uoms.delete');

            Route::get('inventories', [InventoryController::class, 'index'])->middleware('permission:setup.inventories.view');
            Route::post('inventories', [InventoryController::class, 'store'])->middleware('permission:setup.inventories.create');
            Route::get('inventories/{inventory}', [InventoryController::class, 'show'])->middleware('permission:setup.inventories.view');
            Route::post('inventories/{inventory}', [InventoryController::class, 'update'])->middleware('permission:setup.inventories.update');
            Route::post('inventories/{inventory}/toggle-status', [InventoryController::class, 'toggleStatus'])->middleware('permission:setup.inventories.update');
            Route::delete('inventories/{inventory}', [InventoryController::class, 'destroy'])->middleware('permission:setup.inventories.delete');

            Route::get('foods', [FoodController::class, 'index'])->middleware('permission:setup.foods.view');
            Route::post('foods', [FoodController::class, 'store'])->middleware('permission:setup.foods.create');
            Route::get('foods/{food}', [FoodController::class, 'show'])->middleware('permission:setup.foods.view');
            Route::post('foods/{food}', [FoodController::class, 'update'])->middleware('permission:setup.foods.update');
            Route::post('foods/{food}/toggle-status', [FoodController::class, 'toggleStatus'])->middleware('permission:setup.foods.update');
            Route::delete('foods/{food}', [FoodController::class, 'destroy'])->middleware('permission:setup.foods.delete');

            Route::get('medicines', [MedicineController::class, 'index'])->middleware('permission:setup.medicines.view');
            Route::post('medicines', [MedicineController::class, 'store'])->middleware('permission:setup.medicines.create');
            Route::get('medicines/{medicine}', [MedicineController::class, 'show'])->middleware('permission:setup.medicines.view');
            Route::post('medicines/{medicine}', [MedicineController::class, 'update'])->middleware('permission:setup.medicines.update');
            Route::post('medicines/{medicine}/toggle-status', [MedicineController::class, 'toggleStatus'])->middleware('permission:setup.medicines.update');
            Route::delete('medicines/{medicine}', [MedicineController::class, 'destroy'])->middleware('permission:setup.medicines.delete');

            Route::get('animals', [AnimalController::class, 'index'])->middleware('permission:setup.animals.view');
            Route::post('animals', [AnimalController::class, 'store'])->middleware('permission:setup.animals.create');
            Route::get('animals/{animal}', [AnimalController::class, 'show'])->middleware('permission:setup.animals.view');
            Route::post('animals/{animal}', [AnimalController::class, 'update'])->middleware('permission:setup.animals.update');
            Route::delete('animals/{animal}', [AnimalController::class, 'destroy'])->middleware('permission:setup.animals.delete');

            Route::get('equipment', [EquipmentController::class, 'index'])->middleware('permission:setup.equipment.view');
            Route::post('equipment', [EquipmentController::class, 'store'])->middleware('permission:setup.equipment.create');
            Route::get('equipment/{equipment}', [EquipmentController::class, 'show'])->middleware('permission:setup.equipment.view');
            Route::post('equipment/{equipment}', [EquipmentController::class, 'update'])->middleware('permission:setup.equipment.update');
            Route::delete('equipment/{equipment}', [EquipmentController::class, 'destroy'])->middleware('permission:setup.equipment.delete');

            Route::get('farm-information', [FarmInformationController::class, 'index'])->middleware('permission:setup.farm-information.view');
            Route::post('farm-information', [FarmInformationController::class, 'store'])->middleware('permission:setup.farm-information.create');
            Route::get('farm-information/{farmInformation}', [FarmInformationController::class, 'show'])->middleware('permission:setup.farm-information.view');
            Route::post('farm-information/{farmInformation}', [FarmInformationController::class, 'update'])->middleware('permission:setup.farm-information.update');
            Route::delete('farm-information/{farmInformation}', [FarmInformationController::class, 'destroy'])->middleware('permission:setup.farm-information.delete');
            Route::get('activity-logs', [ActivityLogController::class, 'index'])->middleware('permission:setup.audit.view');
            Route::get('activity-logs/{activityLog}', [ActivityLogController::class, 'show'])->middleware('permission:setup.audit.view');
        });
    });
});
