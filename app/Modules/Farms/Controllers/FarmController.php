<?php

namespace App\Modules\Farms\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Farms\Resources\FarmAnimalResource;
use App\Modules\Farms\Resources\FarmDetailResource;
use App\Modules\Farms\Resources\FarmListResource;
use App\Modules\Farms\Services\FarmNavigationService;
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

    public function animals(Request $request, FarmInformation $farmInformation): AnonymousResourceCollection
    {
        return FarmAnimalResource::collection(
            $this->farms->paginateAnimals($request->user(), $farmInformation, $request->query())
        );
    }
}
