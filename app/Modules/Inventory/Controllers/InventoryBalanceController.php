<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Resources\InventoryBalanceResource;
use App\Modules\Inventory\Services\InventoryBalanceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InventoryBalanceController extends Controller
{
    public function __construct(private readonly InventoryBalanceService $balances) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return InventoryBalanceResource::collection($this->balances->paginate($request->query()));
    }
}
