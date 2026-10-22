<?php

namespace App\Modules\Setup\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Setup\Resources\DivisionResource;
use App\Modules\Setup\Resources\TownshipResource;
use App\Modules\Setup\Services\GeographyService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GeographyController extends Controller
{
    public function __construct(private readonly GeographyService $geography) {}

    public function divisions(): AnonymousResourceCollection
    {
        return DivisionResource::collection($this->geography->divisions());
    }

    public function townships(string $division): AnonymousResourceCollection
    {
        return TownshipResource::collection($this->geography->townships((int) $division));
    }
}
