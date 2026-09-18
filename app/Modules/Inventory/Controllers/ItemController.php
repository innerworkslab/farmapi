<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Resources\ItemResource;
use App\Modules\Inventory\Services\ItemService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ItemController extends Controller
{
    public function __construct(private readonly ItemService $items) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return ItemResource::collection($this->items->paginate($request->query()));
    }

    public function show(Item $item): ItemResource
    {
        return new ItemResource($item->load(['itemable', 'stockUom']));
    }
}
