<?php

namespace App\Modules\Financial\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepreciationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'asset_name' => $this->asset_name,
            'asset_category_id' => $this->asset_category_id,
            'asset_category' => $this->whenLoaded('assetCategory', fn () => $this->assetCategory ? [
                'id' => $this->assetCategory->id,
                'name' => $this->assetCategory->name,
                'status' => $this->assetCategory->status,
            ] : null),
            'asset_price' => $this->asset_price,
            'monthly_amount' => $this->monthly_amount,
            'total_posted_amount' => $this->total_posted_amount,
            'remaining_amount' => $this->remainingAmount(),
            'currency_code' => $this->currency_code,
            'start_date' => $this->start_date?->toDateString(),
            'next_posting_date' => $this->next_posting_date?->toDateString(),
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch ? [
                'id' => $this->branch->id,
                'code' => $this->branch->code,
                'name' => $this->branch->name,
            ] : null),
            'cashbook_id' => $this->cashbook_id,
            'cashbook' => $this->whenLoaded('cashbook', fn () => $this->cashbook ? [
                'id' => $this->cashbook->id,
                'name' => $this->cashbook->name,
                'type' => $this->cashbook->type,
                'currency_code' => $this->cashbook->currency_code,
            ] : null),
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'direction' => $this->category->direction,
            ] : null),
            'description' => $this->description,
            'status' => $this->status,
            'created_by_id' => $this->created_by_id,
            'activated_by_id' => $this->activated_by_id,
            'activated_at' => $this->activated_at?->toISOString(),
            'cancelled_by_id' => $this->cancelled_by_id,
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'cancellation_reason' => $this->cancellation_reason,
            'version' => $this->version,
            'schedule_lines' => DepreciationScheduleLineResource::collection($this->whenLoaded('scheduleLines')),
        ];
    }

    private function remainingAmount(): string
    {
        $price = $this->toMinor((string) $this->asset_price);
        $posted = $this->toMinor((string) $this->total_posted_amount);
        $remaining = max(0, $price - $posted);

        return intdiv($remaining, 100).'.'.str_pad((string) ($remaining % 100), 2, '0', STR_PAD_LEFT);
    }

    private function toMinor(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
