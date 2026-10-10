<?php

use App\Models\User;
use App\Modules\Financial\Services\DepreciationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('financial:post-depreciation {--date=} {--actor-id=}', function (DepreciationService $depreciations): int {
    $date = $this->option('date') ? CarbonImmutable::parse($this->option('date')) : CarbonImmutable::today();
    $actor = $this->option('actor-id')
        ? User::query()->find($this->option('actor-id'))
        : User::role('super-admin')->orderBy('id')->first();

    if (! $actor) {
        $this->error('No actor was found. Pass --actor-id or seed a super-admin user.');

        return 1;
    }

    $result = $depreciations->postDueLines($date, $actor);
    $this->info("Processed {$result['processed']} depreciation line(s). Posted {$result['posted']}; failed {$result['failed']}.");

    return 0;
})->purpose('Post due single-entry depreciation cashbook deductions');

Schedule::command('financial:post-depreciation')
    ->dailyAt('00:10')
    ->withoutOverlapping()
    ->description('Post due single-entry depreciation cashbook deductions');
