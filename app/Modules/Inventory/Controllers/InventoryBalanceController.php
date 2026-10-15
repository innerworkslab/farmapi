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
        $validated = $request->validate([
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
        ]);
        return InventoryBalanceResource::collection($this->balances->paginate(array_merge($request->query(), $validated), $request->user()));
    }
}
