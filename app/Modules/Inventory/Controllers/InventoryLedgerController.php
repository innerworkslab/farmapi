<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Resources\InventoryLedgerEntryResource;
use App\Modules\Inventory\Services\InventoryLedgerService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InventoryLedgerController extends Controller
{
    public function __construct(private readonly InventoryLedgerService $ledger) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return InventoryLedgerEntryResource::collection($this->ledger->paginate($request->query()));
    }
}
