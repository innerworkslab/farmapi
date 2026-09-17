<?php

namespace App\Modules\Setup\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Setup\Resources\ActivityLogResource;
use App\Modules\Setup\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\Activitylog\Models\Activity;

class ActivityLogController extends Controller
{
    public function __construct(private readonly ActivityLogService $activityLogs) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return ActivityLogResource::collection($this->activityLogs->paginate($request->query()));
    }

    public function show(Activity $activityLog): ActivityLogResource
    {
        return new ActivityLogResource($activityLog);
    }
}
