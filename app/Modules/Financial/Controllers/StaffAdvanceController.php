<?php

namespace App\Modules\Financial\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Financial\Models\StaffAdvance;
use App\Modules\Financial\Requests\StaffAdvanceBalanceRequest;
use App\Modules\Financial\Requests\StaffAdvanceHistoryRequest;
use App\Modules\Financial\Requests\StaffAdvanceIndexRequest;
use App\Modules\Financial\Requests\StaffAdvanceRequest;
use App\Modules\Financial\Requests\StaffAdvanceRepaymentRequest;
use App\Modules\Financial\Requests\StaffAdvanceReversalRequest;
use App\Modules\Financial\Resources\StaffAdvanceBalanceResource;
use App\Modules\Financial\Resources\StaffAdvanceLedgerEntryResource;
use App\Modules\Financial\Resources\StaffAdvanceRepaymentResource;
use App\Modules\Financial\Resources\StaffAdvanceResource;
use App\Modules\Financial\Services\StaffAdvanceRepaymentService;
use App\Modules\Financial\Services\StaffAdvanceService;
use App\Modules\Setup\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StaffAdvanceController extends Controller
{
    public function __construct(
        private readonly StaffAdvanceService $advances,
        private readonly StaffAdvanceRepaymentService $repayments
    ) {}

    public function balances(StaffAdvanceBalanceRequest $request): AnonymousResourceCollection
    {
        return StaffAdvanceBalanceResource::collection($this->advances->balances($request->validated(), $request->user()));
    }

    public function index(StaffAdvanceIndexRequest $request): AnonymousResourceCollection
    {
        return StaffAdvanceResource::collection($this->advances->paginate($request->validated(), $request->user()));
    }

    public function store(StaffAdvanceRequest $request): StaffAdvanceResource
    {
        return new StaffAdvanceResource($this->advances->createDraft($request->validated(), $request->user()));
    }

    public function show(StaffAdvance $staffAdvance, Request $request): StaffAdvanceResource
    {
        return new StaffAdvanceResource($this->advances->find($staffAdvance, $request->user()));
    }

    public function update(StaffAdvanceRequest $request, StaffAdvance $staffAdvance): StaffAdvanceResource
    {
        return new StaffAdvanceResource($this->advances->updateDraft($staffAdvance, $request->validated(), $request->user()));
    }

    public function confirm(StaffAdvance $staffAdvance, Request $request): StaffAdvanceResource
    {
        return new StaffAdvanceResource($this->advances->confirm($staffAdvance, $request->user()));
    }

    public function reverse(StaffAdvanceReversalRequest $request, StaffAdvance $staffAdvance): StaffAdvanceResource
    {
        return new StaffAdvanceResource($this->advances->reverse($staffAdvance, $request->validated('reason'), $request->user()));
    }

    public function repayments(StaffAdvance $staffAdvance, Request $request): AnonymousResourceCollection
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        return StaffAdvanceRepaymentResource::collection($this->repayments->paginate($staffAdvance, $request->user(), $perPage));
    }

    public function storeRepayment(StaffAdvanceRepaymentRequest $request, StaffAdvance $staffAdvance): StaffAdvanceRepaymentResource
    {
        return new StaffAdvanceRepaymentResource($this->repayments->createDraft($staffAdvance, $request->validated(), $request->user()));
    }

    public function history(StaffAdvanceHistoryRequest $request, Staff $staff): AnonymousResourceCollection
    {
        $history = $this->advances->history($staff, $request->validated(), $request->user());

        return StaffAdvanceLedgerEntryResource::collection($history['entries'])
            ->additional(['meta' => ['staff_id' => $staff->id, 'current_balance' => $history['current_balance']]]);
    }
}
