<?php

namespace App\Modules\Financial\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Financial\Models\StaffAdvanceRepayment;
use App\Modules\Financial\Requests\StaffAdvanceRepaymentRequest;
use App\Modules\Financial\Requests\StaffAdvanceReversalRequest;
use App\Modules\Financial\Resources\StaffAdvanceRepaymentResource;
use App\Modules\Financial\Services\StaffAdvanceRepaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffAdvanceRepaymentController extends Controller
{
    public function __construct(private readonly StaffAdvanceRepaymentService $repayments) {}

    public function show(StaffAdvanceRepayment $repayment, Request $request): StaffAdvanceRepaymentResource
    {
        return new StaffAdvanceRepaymentResource($this->repayments->find($repayment, $request->user()));
    }

    public function update(StaffAdvanceRepaymentRequest $request, StaffAdvanceRepayment $repayment): StaffAdvanceRepaymentResource
    {
        return new StaffAdvanceRepaymentResource($this->repayments->updateDraft($repayment, $request->validated(), $request->user()));
    }

    public function confirm(StaffAdvanceRepayment $repayment, Request $request): StaffAdvanceRepaymentResource
    {
        return new StaffAdvanceRepaymentResource($this->repayments->confirm($repayment, $request->user()));
    }

    public function reverse(StaffAdvanceReversalRequest $request, StaffAdvanceRepayment $repayment): StaffAdvanceRepaymentResource
    {
        return new StaffAdvanceRepaymentResource($this->repayments->reverse($repayment, $request->validated('reason'), $request->user()));
    }

    public function cancel(StaffAdvanceReversalRequest $request, StaffAdvanceRepayment $repayment): JsonResponse
    {
        $this->repayments->cancelDraft($repayment, $request->validated('reason'), $request->user());

        return response()->json(['message' => 'Draft repayment cancelled.']);
    }
}
