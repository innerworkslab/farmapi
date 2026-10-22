<?php

namespace App\Modules\Setup\Repositories;

use App\Modules\Setup\Models\Division;
use App\Modules\Setup\Models\Township;
use Illuminate\Support\Collection;

class GeographyRepository
{
    public function divisions(): Collection
    {
        return Division::query()->where('status', 'active')->orderBy('name')->orderBy('id')->get(['id', 'name']);
    }

    public function divisionExists(int $divisionId): bool
    {
        return Division::query()->whereKey($divisionId)->where('status', 'active')->exists();
    }

    public function townships(int $divisionId): Collection
    {
        return Township::query()->where('division_id', $divisionId)->where('status', 'active')
            ->orderBy('name')->orderBy('id')->get(['id', 'division_id', 'name']);
    }
}
