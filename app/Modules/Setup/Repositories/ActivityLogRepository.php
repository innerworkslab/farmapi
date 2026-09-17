<?php

namespace App\Modules\Setup\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

class ActivityLogRepository
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return Activity::query()
            ->when($filters['log_name'] ?? null, fn (Builder $query, string $logName) => $query->where('log_name', $logName))
            ->when($filters['event'] ?? null, fn (Builder $query, string $event) => $query->where('event', $event))
            ->when($filters['subject_type'] ?? null, fn (Builder $query, string $subjectType) => $query->where('subject_type', $subjectType))
            ->when($filters['subject_id'] ?? null, fn (Builder $query, int|string $subjectId) => $query->where('subject_id', $subjectId))
            ->when($filters['causer_id'] ?? null, fn (Builder $query, int|string $causerId) => $query->where('causer_id', $causerId))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->where('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->where('created_at', '<=', $to))
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }
}
