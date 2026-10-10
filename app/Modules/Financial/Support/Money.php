<?php

namespace App\Modules\Financial\Support;

use Illuminate\Validation\ValidationException;

final class Money
{
    public static function minor(string|int|float $value, string $field = 'amount'): int
    {
        if (! preg_match('/^(-?)(\d{1,16})(?:\.(\d{1,2}))?$/', trim((string) $value), $m)) {
            throw ValidationException::withMessages([$field => 'Use a decimal amount with at most two decimal places.']);
        }
        $minor = ((int) $m[2] * 100) + (int) str_pad($m[3] ?? '', 2, '0');

        return ($m[1] === '-' ? -1 : 1) * $minor;
    }

    public static function decimal(int $minor): string
    {
        return ($minor < 0 ? '-' : '').intdiv(abs($minor), 100).'.'.str_pad((string) (abs($minor) % 100), 2, '0', STR_PAD_LEFT);
    }
}
