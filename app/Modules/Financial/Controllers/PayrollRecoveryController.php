<?php

namespace App\Modules\Financial\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Financial\Models\StaffAdvance;
use App\Modules\Financial\Models\StaffPayrollRecovery;
use App\Modules\Financial\Requests\FinancialReasonRequest;
use App\Modules\Financial\Requests\PayrollRecoveryRequest;
use App\Modules\Financial\Requests\PayrollVerificationRequest;
use App\Modules\Financial\Resources\FinancialRecordResource;
use App\Modules\Financial\Services\PayrollRecoveryService;
use Illuminate\Http\Request;

class PayrollRecoveryController extends Controller
{
    public function __construct(private readonly PayrollRecoveryService $recoveries) {}

    public function index(Request $request, StaffAdvance $staffAdvance)
    {
        return FinancialRecordResource::collection($this->recoveries->list($staffAdvance, $request->user()));
    }

    public function store(PayrollRecoveryRequest $request, StaffAdvance $staffAdvance)
    {
        return (new FinancialRecordResource($this->recoveries->create($staffAdvance, $request->validated(), $request->user())))->response()->setStatusCode(201);
    }

    public function confirm(PayrollVerificationRequest $request, StaffPayrollRecovery $recovery)
    {
        return new FinancialRecordResource($this->recoveries->confirm($recovery, $request->validated('verification_note'), $request->user()));
    }

    public function reverse(FinancialReasonRequest $request, StaffPayrollRecovery $recovery)
    {
        return new FinancialRecordResource($this->recoveries->reverse($recovery, $request->validated('reason'), $request->user()));
    }

    public function cancel(Request $request, StaffPayrollRecovery $recovery)
    {
        return new FinancialRecordResource($this->recoveries->cancel($recovery, $request->user()));
    }
}
