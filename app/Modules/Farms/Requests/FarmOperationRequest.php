<?php

namespace App\Modules\Farms\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FarmOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['health', 'mortality', 'medication', 'weight'])],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'farm_information_id' => ['required', 'integer', 'exists:farm_information,id'],
            'activity_date' => ['required', 'date', 'before_or_equal:today'],
            'activity_time' => ['nullable', 'date_format:H:i'],
            'idempotency_key' => ['required', 'string', 'max:150'],
            'version' => ['sometimes', 'integer', 'min:1'],
            'targets' => ['required', 'array', 'min:1'],
            'targets.*' => ['required', 'array:animal_balance_id,quantity'],
            'targets.*.animal_balance_id' => ['required', 'integer', 'distinct', 'exists:inventory_balances,id'],
            'targets.*.quantity' => ['required', 'integer', 'min:1'],
            'payload' => ['required', 'array:reason,health_status,defect_type,symptoms,severity,description,suspected_cause,action_taken,isolation_required,veterinarian_required,veterinarian_id,veterinarian_notes,disposal_method,disposal_location,age_at_death,weight,weight_uom,follow_up_date,withdrawal_days,administration_route,treatment_result,vaccination,notes,attachments,lines'],
            'payload.reason' => ['required', 'string', 'max:2000'],
            'payload.health_status' => ['required_if:type,health', Rule::in(['healthy', 'under_observation', 'sick', 'under_treatment', 'quarantine', 'critical', 'recovered'])],
            'payload.defect_type' => ['nullable', 'string', 'max:255'],
            'payload.symptoms' => ['nullable', 'string', 'max:2000'],
            'payload.severity' => ['nullable', Rule::in(['mild', 'moderate', 'severe', 'critical'])],
            'payload.description' => ['nullable', 'string', 'max:4000'],
            'payload.suspected_cause' => ['nullable', 'string', 'max:2000'],
            'payload.action_taken' => ['nullable', 'string', 'max:4000'],
            'payload.isolation_required' => ['nullable', 'boolean'],
            'payload.veterinarian_required' => ['nullable', 'boolean'],
            'payload.veterinarian_id' => ['nullable', 'integer', 'exists:users,id'],
            'payload.veterinarian_notes' => ['nullable', 'string', 'max:4000'],
            'payload.disposal_method' => ['required_if:type,mortality', 'string', 'max:255'],
            'payload.disposal_location' => ['nullable', 'string', 'max:255'],
            'payload.age_at_death' => ['nullable', 'numeric', 'min:0'],
            'payload.weight' => ['required_if:type,weight', 'numeric', 'gt:0'],
            'payload.weight_uom' => ['nullable', Rule::in(['kg', 'g', 'lb'])],
            'payload.follow_up_date' => ['nullable', 'date', 'after_or_equal:activity_date'],
            'payload.withdrawal_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'payload.administration_route' => ['required_if:type,medication', 'string', 'max:100'],
            'payload.treatment_result' => ['nullable', 'string', 'max:2000'],
            'payload.vaccination' => ['nullable', 'boolean'],
            'payload.notes' => ['nullable', 'string', 'max:4000'],
            'payload.attachments' => ['nullable', 'array'],
            'payload.attachments.*' => ['string', 'max:500'],
            'payload.lines' => ['required_if:type,medication', 'array', 'min:1'],
            'payload.lines.*.medicine_item_id' => ['required', 'integer', 'exists:items,id'],
            'payload.lines.*.inventory_id' => ['required', 'integer', 'exists:inventories,id'],
            'payload.lines.*.stock_lot_id' => ['nullable', 'integer', 'exists:inventory_stock_lots,id'],
            'payload.lines.*.source_location' => ['required', 'string', 'max:100'],
            'payload.lines.*.stock_uom_id' => ['required', 'integer', 'exists:uoms,id'],
            'payload.lines.*.dosage' => ['required', 'numeric', 'gt:0'],
            'payload.lines.*.dosage_uom_id' => ['required', 'integer', 'exists:uoms,id'],
            'payload.lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
