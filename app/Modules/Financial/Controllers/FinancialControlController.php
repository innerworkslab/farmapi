<?php

namespace App\Modules\Financial\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Financial\Models\FinancialPeriod;
use App\Modules\Financial\Requests\FinancialLimitRequest;
use App\Modules\Financial\Requests\FinancialPeriodRequest;
use App\Modules\Financial\Requests\FinancialReasonRequest;
use App\Modules\Financial\Requests\FinancialSettingRequest;
use App\Modules\Financial\Resources\FinancialRecordResource;
use App\Modules\Financial\Services\FinancialControlService;
use App\Modules\Setup\Models\Branch;
use Illuminate\Http\Request;

class FinancialControlController extends Controller
{
    public function __construct(private readonly FinancialControlService $controls) {}

    public function settings(Request $request, Branch $branch)
    {
        $settings = $this->controls->settings($branch->id, $request->user());

        return response()->json(['data' => $settings ? new FinancialRecordResource($settings) : null, 'configured' => $settings !== null, 'currency_precision' => 2]);
    }

    public function configure(FinancialSettingRequest $request, Branch $branch)
    {
        return new FinancialRecordResource($this->controls->configure($branch->id, $request->validated(), $request->user()));
    }

    public function periods(Request $request)
    {
        return FinancialRecordResource::collection($this->controls->periods($request->user()));
    }

    public function createPeriod(FinancialPeriodRequest $request)
    {
        return (new FinancialRecordResource($this->controls->createPeriod($request->validated(), $request->user())))->response()->setStatusCode(201);
    }

    public function close(FinancialReasonRequest $request, FinancialPeriod $period)
    {
        return new FinancialRecordResource($this->controls->transition($period, true, $request->validated('reason'), $request->user()));
    }

    public function reopen(FinancialReasonRequest $request, FinancialPeriod $period)
    {
        return new FinancialRecordResource($this->controls->transition($period, false, $request->validated('reason'), $request->user()));
    }

    public function limits(Request $request, Branch $branch)
    {
        return FinancialRecordResource::collection($this->controls->limits($branch->id, $request->user()));
    }

    public function setLimit(FinancialLimitRequest $request, Branch $branch)
    {
        return new FinancialRecordResource($this->controls->setLimit($branch->id, $request->validated(), $request->user()));
    }
}
