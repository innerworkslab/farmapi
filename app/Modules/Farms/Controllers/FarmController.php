<?php

namespace App\Modules\Farms\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Farms\Resources\FarmAnimalResource;
use App\Modules\Farms\Resources\FarmAnimalViewSummaryResource;
use App\Modules\Farms\Resources\FarmDetailResource;
use App\Modules\Farms\Resources\FarmListResource;
use App\Modules\Farms\Services\FarmNavigationService;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Setup\Models\FarmInformation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FarmController extends Controller
{
    public function __construct(private readonly FarmNavigationService $farms) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return FarmListResource::collection($this->farms->paginate($request->user(), $request->query()));
    }

    public function show(Request $request, FarmInformation $farmInformation): FarmDetailResource
    {
        return new FarmDetailResource($this->farms->detail($request->user(), $farmInformation));
    }

    public function animalViewSummary(Request $request, FarmInformation $farmInformation): FarmAnimalViewSummaryResource
    {
        return new FarmAnimalViewSummaryResource(
            $this->farms->animalViewSummary($request->user(), $farmInformation, $request->query())
        );
    }

    public function animals(Request $request, FarmInformation $farmInformation): AnonymousResourceCollection
    {
        return FarmAnimalResource::collection(
            $this->farms->paginateAnimals($request->user(), $farmInformation, $request->query())
        );
    }

    public function animal(Request $request, FarmInformation $farmInformation, InventoryBalance $animalBalance): FarmAnimalResource
    {
        return new FarmAnimalResource(
            $this->farms->animalDetail($request->user(), $farmInformation, $animalBalance)
        );
    }
}