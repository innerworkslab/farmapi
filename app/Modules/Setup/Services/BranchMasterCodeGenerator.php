<?php

namespace App\Modules\Setup\Services;

use App\Modules\Setup\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BranchMasterCodeGenerator
{
    public function next(Branch $branch, string $table, string $kind): string
    {
        if (! in_array($table, ['customers', 'suppliers'], true) || ! in_array($kind, ['CUS', 'SUP'], true)) {
            throw new \InvalidArgumentException('Unsupported branch master code sequence.');
        }
        $branch = Branch::query()->whereKey($branch->id)->lockForUpdate()->firstOrFail();
        if ($branch->status !== Branch::STATUS_ACTIVE || ! $branch->code) {
            throw ValidationException::withMessages(['preferred_branch_id' => 'Select an active branch with a branch code.']);
        }

        // The branch row serializes code allocation for both master types in this branch.
        $prefix = $branch->code.'-'.$kind.'-';
        $pattern = '/^'.preg_quote($prefix, '/').'([0-9]+)$/';
        $last = DB::table($table)->where('code', 'like', $prefix.'%')->pluck('code')
            ->map(fn (string $code): int => preg_match($pattern, $code, $match) ? (int) $match[1] : 0)
            ->max() ?? 0;

        return $prefix.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }
}
