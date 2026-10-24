<?php

namespace Database\Seeders;

use App\Modules\Financial\Models\CashLedgerCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PurchasePaymentCategorySeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $out = CashLedgerCategory::withTrashed()->firstOrCreate(['normalized_name' => 'purchases', 'direction' => 'out'],
                ['name' => 'Purchases', 'report_treatment' => 'expense', 'status' => 'active']);
            if (! $out->trashed() && ! $out->reversal_category_id) {
                $in = CashLedgerCategory::withTrashed()->firstOrCreate(['normalized_name' => 'purchase payment reversal', 'direction' => 'in'],
                    ['name' => 'Purchase Payment Reversal', 'report_treatment' => 'unclassified', 'status' => 'active', 'reversal_category_id' => $out->id]);
                if (! $in->trashed()) {
                    $out->update(['reversal_category_id' => $in->id]);
                }
            }
        });
    }
}
