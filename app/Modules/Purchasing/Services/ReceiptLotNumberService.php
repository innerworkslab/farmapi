<?php

namespace App\Modules\Purchasing\Services;

use Illuminate\Support\Facades\DB;

class ReceiptLotNumberService
{
    public function next(): string
    {
        return DB::transaction(function (): string {
            $sequence = DB::table('purchase_receipt_lot_sequence')->where('id', 1)->lockForUpdate()->firstOrFail();
            $value = (int) $sequence->last_value;

            do {
                $number = 'LOT-'.str_pad((string) ++$value, 6, '0', STR_PAD_LEFT);
            } while (DB::table('purchase_receipt_lines')->where('receipt_lot_number', $number)->exists()
                || DB::table('inventory_stock_lots')->where('receipt_lot_number', $number)->exists());

            DB::table('purchase_receipt_lot_sequence')->where('id', 1)->update(['last_value' => $value]);

            return $number;
        });
    }
}
