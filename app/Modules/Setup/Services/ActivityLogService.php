<?php

namespace App\Modules\Setup\Services;

use App\Modules\Setup\Repositories\ActivityLogRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ActivityLogService
{
    public function __construct(private readonly ActivityLogRepository $activityLogs) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return $this->activityLogs->paginate($filters);
    }
}
