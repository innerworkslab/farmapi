<?php

namespace App\Modules\Setup\Services;

use App\Modules\Setup\Repositories\GeographyRepository;
use Illuminate\Support\Collection;

class GeographyService
{
    public function __construct(private readonly GeographyRepository $geography) {}

    public function divisions(): Collection
    {
        return $this->geography->divisions();
    }

    public function townships(int $divisionId): Collection
    {
        abort_unless($this->geography->divisionExists($divisionId), 404, 'State or region not found.');

        return $this->geography->townships($divisionId);
    }
}
