<?php

namespace App\Modules\Setup\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Setup\Models\Staff;
use App\Modules\Setup\Requests\StaffIndexRequest;
use App\Modules\Setup\Requests\StaffRequest;
use App\Modules\Setup\Resources\StaffResource;
use App\Modules\Setup\Services\StaffService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StaffController extends Controller
{
    public function __construct(private readonly StaffService $staff) {}

    public function index(StaffIndexRequest $request): AnonymousResourceCollection
    {
        return StaffResource::collection($this->staff->paginate($request->validated(), $request->user()));
    }

    public function store(StaffRequest $request): StaffResource
    {
        return new StaffResource($this->staff->create($request->validated(), $request->user()));
    }

    public function show(Staff $staff, Request $request): StaffResource
    {
        return new StaffResource($this->staff->find($staff, $request->user()));
    }

    public function update(StaffRequest $request, Staff $staff): StaffResource
    {
        return new StaffResource($this->staff->update($staff, $request->validated(), $request->user()));
    }

    public function toggleStatus(Staff $staff, Request $request): StaffResource
    {
        return new StaffResource($this->staff->toggleStatus($staff, $request->user()));
    }
}
