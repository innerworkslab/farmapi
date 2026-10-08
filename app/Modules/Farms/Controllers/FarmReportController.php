<?php

namespace App\Modules\Farms\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Farms\Models\FarmAlert;
use App\Modules\Farms\Requests\FarmActionRequest;
use App\Modules\Farms\Requests\FarmReportQueryRequest;
use App\Modules\Farms\Requests\FarmSettingsRequest;
use App\Modules\Farms\Requests\FeedingPlanRequest;
use App\Modules\Farms\Services\FarmReportService;
use App\Modules\Setup\Models\FarmInformation;
use Illuminate\Http\Request;

class FarmReportController extends Controller
{
    public function __construct(private FarmReportService $reports) {}

    public function summary(FarmReportQueryRequest $request, FarmInformation $farmInformation)
    {
        return response()->json(['data' => $this->reports->summary($farmInformation, $request->user(), $request->validated('date'))]);
    }

    public function timeline(FarmReportQueryRequest $request, FarmInformation $farmInformation)
    {
        return response()->json(['data' => $this->reports->timeline($farmInformation, $request->validated(), $request->user())]);
    }

    public function mortality(FarmReportQueryRequest $request, FarmInformation $farmInformation)
    {
        return response()->json(['data' => $this->reports->mortality($farmInformation, $request->user(), $request->validated('date'))]);
    }

    public function alerts(Request $request, FarmInformation $farmInformation)
    {
        return response()->json(['data' => $this->reports->alerts($farmInformation, $request->user())]);
    }

    public function settings(Request $request, FarmInformation $farmInformation)
    {
        return response()->json(['data' => $this->reports->settings($farmInformation, $request->user())]);
    }

    public function saveSettings(FarmSettingsRequest $request, FarmInformation $farmInformation)
    {
        return response()->json(['data' => $this->reports->saveSettings($farmInformation, $request->validated(), $request->user())]);
    }

    public function plans(FarmReportQueryRequest $request, FarmInformation $farmInformation)
    {
        return response()->json($this->reports->plans($farmInformation, $request->validated(), $request->user()));
    }

    public function savePlan(FeedingPlanRequest $request, FarmInformation $farmInformation)
    {
        return response()->json(['data' => $this->reports->savePlan($farmInformation, $request->validated(), $request->user())]);
    }

    public function toggleStatus(Request $request, FarmInformation $farmInformation)
    {
        return response()->json(['data' => $this->reports->toggleStatus($farmInformation, $request->user())]);
    }

    public function investigate(FarmActionRequest $request, FarmAlert $alert)
    {
        return response()->json(['data' => $this->reports->investigate($alert, $request->validated('notes'), $request->user())]);
    }

    public function notifications(Request $request)
    {
        return response()->json(['data' => $this->reports->notifications($request->user())]);
    }
}
