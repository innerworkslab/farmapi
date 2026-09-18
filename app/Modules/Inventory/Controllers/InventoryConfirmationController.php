<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\InventoryConfirmation;
use App\Modules\Inventory\Resources\InventoryConfirmationResource;
use App\Modules\Inventory\Services\InventoryConfirmationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InventoryConfirmationController extends Controller
{
    public function __construct(private readonly InventoryConfirmationService $confirmations) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return InventoryConfirmationResource::collection($this->confirmations->paginate($request->query()));
    }

    public function show(InventoryConfirmation $confirmation): InventoryConfirmationResource
    {
        return new InventoryConfirmationResource($confirmation->load('ledgerEntries'));
    }
}
