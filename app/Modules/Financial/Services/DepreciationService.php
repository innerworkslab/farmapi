<?php

namespace App\Modules\Financial\Services;

use App\Models\User;
use App\Modules\Financial\Models\Cashbook;
use App\Modules\Financial\Models\CashbookTransaction;
use App\Modules\Financial\Models\Depreciation;
use App\Modules\Financial\Models\DepreciationScheduleLine;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class DepreciationService
{
    public function __construct(
        private readonly CashbookService $cashbooks,
        private readonly CashLedgerCategoryService $categories,
        private readonly AssetCategoryService $assetCategories,
        private readonly CashbookTransactionService $cashbookTransactions
    ) {}

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters, User $actor): LengthAwarePaginator
    {
        $query = Depreciation::query()->with(['branch', 'cashbook', 'category', 'assetCategory']);
        $this->scopeToActorBranches($query, $actor);
        $query->when($filters['branch_id'] ?? null, fn (Builder $q, int|string $id) => $q->where('branch_id', $id))
            ->when($filters['cashbook_id'] ?? null, fn (Builder $q, int|string $id) => $q->where('cashbook_id', $id))
            ->when($filters['category_id'] ?? null, fn (Builder $q, int|string $id) => $q->where('category_id', $id))
            ->when($filters['asset_category_id'] ?? null, fn (Builder $q, int|string $id) => $q->where('asset_category_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['from_date'] ?? null, fn (Builder $q, string $date) => $q->whereDate('start_date', '>=', $date))
            ->when($filters['to_date'] ?? null, fn (Builder $q, string $date) => $q->whereDate('start_date', '<=', $date))
            ->when($filters['search'] ?? null, function (Builder $q, string $search): void {
                $search = trim($search);
                $q->where(function (Builder $nested) use ($search): void {
                    $nested->where('reference', 'like', "%{$search}%")
                        ->orWhere('asset_name', 'like', "%{$search}%")
                        ->orWhereHas('assetCategory', fn (Builder $category) => $category->where('name', 'like', "%{$search}%"));
                });
            });

        return $query->orderByDesc('start_date')->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 15));
    }

    /** @param array<string, mixed> $data */
    public function createDraft(array $data, User $actor): Depreciation
    {
        return DB::transaction(function () use ($data, $actor): Depreciation {
            $existing = Depreciation::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                if (! $this->matchesDraftPayload($existing, $data)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This key was already used for different depreciation data.']);
                }

                return $this->find($existing, $actor);
            }

            $cashbook = Cashbook::query()->lockForUpdate()->findOrFail($data['cashbook_id']);
            $cashbook = $this->assertCashbookUsable($cashbook, $actor);
            $assetCategory = $this->assetCategories->findActive((int) $data['asset_category_id']);
            $category = $this->categories->findActiveForDirection((int) $data['category_id'], 'out');
            $assetPrice = $this->formatMinor($this->toMinor((string) $data['asset_price']));
            $monthlyAmount = $this->formatMinor($this->toMinor((string) $data['monthly_amount']));
            $this->assertMonthlyAmount($assetPrice, $monthlyAmount);

            $depreciation = Depreciation::query()->create([
                'branch_id' => $cashbook->branch_id,
                'reference' => 'DEP-'.Str::upper((string) Str::ulid()),
                'idempotency_key' => $data['idempotency_key'],
                'asset_name' => trim($data['asset_name']),
                'asset_category_id' => $assetCategory->id,
                'asset_price' => $assetPrice,
                'monthly_amount' => $monthlyAmount,
                'total_posted_amount' => '0.00',
                'currency_code' => $cashbook->currency_code,
                'start_date' => $data['start_date'],
                'cashbook_id' => $cashbook->id,
                'category_id' => $category->id,
                'description' => $this->blankToNull($data['description'] ?? null),
                'status' => Depreciation::STATUS_DRAFT,
                'created_by_id' => $actor->id,
                'version' => 1,
            ]);

            return $this->find($depreciation, $actor);
        });
    }

    /** @param array<string, mixed> $data */
    public function updateDraft(Depreciation $depreciation, array $data, User $actor): Depreciation
    {
        return DB::transaction(function () use ($depreciation, $data, $actor): Depreciation {
            $depreciation = Depreciation::query()->lockForUpdate()->findOrFail($depreciation->id);
            $this->assertDepreciationAccess($depreciation, $actor);
            if ($depreciation->status !== Depreciation::STATUS_DRAFT) {
                throw ValidationException::withMessages(['depreciation' => 'Only draft depreciation records can be edited.']);
            }

            $cashbookId = (int) ($data['cashbook_id'] ?? $depreciation->cashbook_id);
            $categoryId = (int) ($data['category_id'] ?? $depreciation->category_id);
            $assetCategoryId = (int) ($data['asset_category_id'] ?? $depreciation->asset_category_id);
            $cashbook = Cashbook::query()->lockForUpdate()->findOrFail($cashbookId);
            $cashbook = $this->assertCashbookUsable($cashbook, $actor);
            $assetCategory = $this->assetCategories->findActive($assetCategoryId);
            $category = $this->categories->findActiveForDirection($categoryId, 'out');

            if (isset($data['asset_name'])) {
                $depreciation->asset_name = trim($data['asset_name']);
            }
            if (isset($data['asset_price'])) {
                $depreciation->asset_price = $this->formatMinor($this->toMinor((string) $data['asset_price']));
            }
            if (isset($data['monthly_amount'])) {
                $depreciation->monthly_amount = $this->formatMinor($this->toMinor((string) $data['monthly_amount']));
            }
            $this->assertMonthlyAmount((string) $depreciation->asset_price, (string) $depreciation->monthly_amount);
            if (isset($data['start_date'])) {
                $depreciation->start_date = $data['start_date'];
            }
            if (array_key_exists('description', $data)) {
                $depreciation->description = $this->blankToNull($data['description']);
            }

            $depreciation->branch_id = $cashbook->branch_id;
            $depreciation->cashbook_id = $cashbook->id;
            $depreciation->asset_category_id = $assetCategory->id;
            $depreciation->category_id = $category->id;
            $depreciation->currency_code = $cashbook->currency_code;
            $depreciation->version++;
            $depreciation->save();

            return $this->find($depreciation->refresh(), $actor);
        });
    }

    public function find(Depreciation $depreciation, User $actor): Depreciation
    {
        $depreciation = Depreciation::query()->with(['branch', 'cashbook', 'category', 'assetCategory', 'scheduleLines.cashbookTransaction'])
            ->findOrFail($depreciation->id);
        $this->assertDepreciationAccess($depreciation, $actor);

        return $depreciation;
    }

    public function activate(Depreciation $depreciation, User $actor): Depreciation
    {
        return DB::transaction(function () use ($depreciation, $actor): Depreciation {
            $depreciation = Depreciation::query()->lockForUpdate()->findOrFail($depreciation->id);
            $this->assertDepreciationAccess($depreciation, $actor);
            if ($depreciation->status === Depreciation::STATUS_ACTIVE || $depreciation->status === Depreciation::STATUS_COMPLETED) {
                return $this->find($depreciation, $actor);
            }
            if ($depreciation->status !== Depreciation::STATUS_DRAFT) {
                throw ValidationException::withMessages(['depreciation' => 'Only draft depreciation records can be activated.']);
            }

            $cashbook = Cashbook::query()->lockForUpdate()->findOrFail($depreciation->cashbook_id);
            $this->assertCashbookUsable($cashbook, $actor);
            $this->assetCategories->findActive((int) $depreciation->asset_category_id);
            $this->categories->findActiveForDirection((int) $depreciation->category_id, 'out');

            $this->generateSchedule($depreciation);
            $depreciation->status = Depreciation::STATUS_ACTIVE;
            $depreciation->next_posting_date = $depreciation->scheduleLines()->where('status', DepreciationScheduleLine::STATUS_PENDING)->orderBy('sequence_number')->value('due_date');
            $depreciation->activated_by_id = $actor->id;
            $depreciation->activated_at = now();
            $depreciation->version++;
            $depreciation->save();

            return $this->find($depreciation->refresh(), $actor);
        });
    }

    public function cancel(Depreciation $depreciation, string $reason, User $actor): Depreciation
    {
        return DB::transaction(function () use ($depreciation, $reason, $actor): Depreciation {
            $depreciation = Depreciation::query()->lockForUpdate()->findOrFail($depreciation->id);
            $this->assertDepreciationAccess($depreciation, $actor);
            if (! in_array($depreciation->status, [Depreciation::STATUS_DRAFT, Depreciation::STATUS_ACTIVE], true)) {
                throw ValidationException::withMessages(['depreciation' => 'Only draft or active depreciation records can be cancelled.']);
            }

            $depreciation->status = Depreciation::STATUS_CANCELLED;
            $depreciation->next_posting_date = null;
            $depreciation->cancelled_by_id = $actor->id;
            $depreciation->cancelled_at = now();
            $depreciation->cancellation_reason = trim($reason);
            $depreciation->version++;
            $depreciation->save();

            return $this->find($depreciation->refresh(), $actor);
        });
    }

    public function postScheduleLine(DepreciationScheduleLine $line, User $actor): DepreciationScheduleLine
    {
        return DB::transaction(function () use ($line, $actor): DepreciationScheduleLine {
            $line = DepreciationScheduleLine::query()->lockForUpdate()->findOrFail($line->id);
            $depreciation = Depreciation::query()->lockForUpdate()->findOrFail($line->depreciation_id);
            $this->assertDepreciationAccess($depreciation, $actor);
            if ($depreciation->status !== Depreciation::STATUS_ACTIVE) {
                throw ValidationException::withMessages(['depreciation' => 'Only active depreciation records can post schedule lines.']);
            }
            if (! in_array($line->status, [DepreciationScheduleLine::STATUS_PENDING, DepreciationScheduleLine::STATUS_FAILED], true)) {
                throw ValidationException::withMessages(['schedule_line' => 'Only pending or failed schedule lines can be posted.']);
            }
            if ($line->due_date->isFuture()) {
                throw ValidationException::withMessages(['due_date' => 'Future depreciation lines cannot be posted.']);
            }

            $cashbook = Cashbook::query()->lockForUpdate()->findOrFail($depreciation->cashbook_id);
            $this->assertCashbookUsable($cashbook, $actor);
            $category = $this->categories->findActiveForDirection((int) $depreciation->category_id, 'out');
            $remaining = $this->toMinor((string) $depreciation->asset_price) - $this->postedTotalMinor((int) $depreciation->id);
            if ($remaining <= 0) {
                throw ValidationException::withMessages(['depreciation' => 'This depreciation record is already fully posted.']);
            }
            if ($this->toMinor((string) $line->amount) > $remaining) {
                $line->amount = $this->formatMinor($remaining);
                $line->save();
            }

            $cashbookTransaction = $this->cashbookTransactions->postForModule([
                'cashbook_id' => $cashbook->id,
                'category_id' => $category->id,
                'direction' => 'out',
                'amount' => $line->amount,
                'business_date' => $line->due_date->toDateString(),
                'description' => 'Depreciation deduction: '.$depreciation->asset_name.' #'.$line->sequence_number,
                'external_reference' => $depreciation->reference.'-'.$line->sequence_number,
                'idempotency_key' => 'DEP-POST-'.$depreciation->reference.'-'.$line->sequence_number,
            ], 'depreciation', $actor);

            $line->status = DepreciationScheduleLine::STATUS_POSTED;
            $line->cashbook_transaction_id = $cashbookTransaction->id;
            $line->posted_by_id = $actor->id;
            $line->posted_at = now();
            $line->failure_reason = null;
            $line->save();

            $this->refreshPostingState($depreciation);

            return $line->refresh()->load(['depreciation', 'cashbookTransaction']);
        });
    }

    /** @return array{processed:int, posted:int, failed:int} */
    public function postDueLines(CarbonImmutable $asOfDate, User $actor): array
    {
        $processed = 0;
        $posted = 0;
        $failed = 0;

        DepreciationScheduleLine::query()
            ->where('status', DepreciationScheduleLine::STATUS_PENDING)
            ->whereDate('due_date', '<=', $asOfDate->toDateString())
            ->whereHas('depreciation', fn (Builder $query) => $query->where('status', Depreciation::STATUS_ACTIVE))
            ->orderBy('due_date')
            ->orderBy('id')
            ->chunkById(50, function ($lines) use ($actor, &$processed, &$posted, &$failed): void {
                foreach ($lines as $line) {
                    $processed++;
                    try {
                        $this->postScheduleLine($line, $actor);
                        $posted++;
                    } catch (Throwable $exception) {
                        $this->markLineFailed($line, $exception);
                        $failed++;
                    }
                }
            });

        return ['processed' => $processed, 'posted' => $posted, 'failed' => $failed];
    }

    public function reverseScheduleLine(DepreciationScheduleLine $line, string $reason, User $actor): DepreciationScheduleLine
    {
        return DB::transaction(function () use ($line, $reason, $actor): DepreciationScheduleLine {
            $line = DepreciationScheduleLine::query()->lockForUpdate()->findOrFail($line->id);
            $depreciation = Depreciation::query()->lockForUpdate()->findOrFail($line->depreciation_id);
            $this->assertDepreciationAccess($depreciation, $actor);
            if ($line->status !== DepreciationScheduleLine::STATUS_POSTED) {
                throw ValidationException::withMessages(['schedule_line' => 'Only posted depreciation lines can be reversed.']);
            }

            $cashbookTransaction = CashbookTransaction::query()->findOrFail($line->cashbook_transaction_id);
            $this->cashbookTransactions->reverseForModule($cashbookTransaction, 'depreciation', $reason, $actor);

            $line->status = DepreciationScheduleLine::STATUS_REVERSED;
            $line->reversed_by_id = $actor->id;
            $line->reversed_at = now();
            $line->reversal_reason = trim($reason);
            $line->save();

            $this->refreshPostingState($depreciation);

            return $line->refresh()->load(['depreciation', 'cashbookTransaction']);
        });
    }

    public function schedule(Depreciation $depreciation, User $actor, int $perPage = 30): LengthAwarePaginator
    {
        $depreciation = $this->find($depreciation, $actor);

        return $depreciation->scheduleLines()->with('cashbookTransaction')->orderBy('sequence_number')->paginate($perPage);
    }

    private function generateSchedule(Depreciation $depreciation): void
    {
        if ($depreciation->scheduleLines()->exists()) {
            return;
        }

        $remaining = $this->toMinor((string) $depreciation->asset_price);
        $monthly = $this->toMinor((string) $depreciation->monthly_amount);
        $date = CarbonImmutable::parse($depreciation->start_date);
        $sequence = 1;
        while ($remaining > 0) {
            $amount = min($monthly, $remaining);
            $depreciation->scheduleLines()->create([
                'sequence_number' => $sequence,
                'due_date' => $date->addMonthsNoOverflow($sequence - 1)->toDateString(),
                'amount' => $this->formatMinor($amount),
                'status' => DepreciationScheduleLine::STATUS_PENDING,
            ]);
            $remaining -= $amount;
            $sequence++;
        }
    }

    private function refreshPostingState(Depreciation $depreciation): void
    {
        $depreciation = Depreciation::query()->lockForUpdate()->findOrFail($depreciation->id);
        $posted = $this->postedTotalMinor((int) $depreciation->id);
        $assetPrice = $this->toMinor((string) $depreciation->asset_price);
        $depreciation->total_posted_amount = $this->formatMinor($posted);
        $depreciation->next_posting_date = $depreciation->scheduleLines()
            ->whereIn('status', [DepreciationScheduleLine::STATUS_PENDING, DepreciationScheduleLine::STATUS_FAILED])
            ->orderBy('sequence_number')
            ->value('due_date');
        if ($posted >= $assetPrice && ! $depreciation->scheduleLines()->whereIn('status', [DepreciationScheduleLine::STATUS_PENDING, DepreciationScheduleLine::STATUS_FAILED])->exists()) {
            $depreciation->status = Depreciation::STATUS_COMPLETED;
            $depreciation->next_posting_date = null;
        } elseif ($depreciation->status === Depreciation::STATUS_COMPLETED && $posted < $assetPrice) {
            $depreciation->status = Depreciation::STATUS_ACTIVE;
        }
        $depreciation->version++;
        $depreciation->save();
    }

    private function postedTotalMinor(int $depreciationId): int
    {
        return DepreciationScheduleLine::query()
            ->where('depreciation_id', $depreciationId)
            ->where('status', DepreciationScheduleLine::STATUS_POSTED)
            ->get(['amount'])
            ->sum(fn (DepreciationScheduleLine $line): int => $this->toMinor((string) $line->amount));
    }

    private function markLineFailed(DepreciationScheduleLine $line, Throwable $exception): void
    {
        DB::transaction(function () use ($line, $exception): void {
            $line = DepreciationScheduleLine::query()->lockForUpdate()->find($line->id);
            if (! $line || $line->status === DepreciationScheduleLine::STATUS_POSTED) {
                return;
            }
            $line->status = DepreciationScheduleLine::STATUS_FAILED;
            $line->failure_reason = mb_substr($exception->getMessage(), 0, 2000);
            $line->save();

            $depreciation = Depreciation::query()->lockForUpdate()->find($line->depreciation_id);
            if ($depreciation) {
                $this->refreshPostingState($depreciation);
            }
        });
    }

    private function assertDepreciationAccess(Depreciation $depreciation, User $actor): void
    {
        $cashbook = Cashbook::withTrashed()->with('branch')->findOrFail($depreciation->cashbook_id);
        $this->cashbooks->assertBranchAccess($cashbook->branch, $actor);
    }

    private function assertCashbookUsable(Cashbook $cashbook, User $actor): Cashbook
    {
        $cashbook = Cashbook::query()->with('branch')->findOrFail($cashbook->id);
        $this->cashbooks->assertBranchAccess($cashbook->branch, $actor);
        if ($cashbook->status !== Cashbook::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['cashbook_id' => 'An active cashbook is required.']);
        }

        return $cashbook;
    }

    private function assertMonthlyAmount(string $assetPrice, string $monthlyAmount): void
    {
        $price = $this->toMinor($assetPrice);
        $monthly = $this->toMinor($monthlyAmount);
        if ($price <= 0 || $monthly <= 0) {
            throw ValidationException::withMessages(['amount' => 'Asset price and monthly amount must be greater than zero.']);
        }
        if ($monthly > $price) {
            throw ValidationException::withMessages(['monthly_amount' => 'Monthly amount cannot exceed the asset price.']);
        }
    }

    /** @param array<string, mixed> $data */
    private function matchesDraftPayload(Depreciation $existing, array $data): bool
    {
        return (int) $existing->cashbook_id === (int) $data['cashbook_id']
            && (int) $existing->category_id === (int) $data['category_id']
            && (int) $existing->asset_category_id === (int) $data['asset_category_id']
            && $existing->asset_name === trim($data['asset_name'])
            && $this->toMinor((string) $existing->asset_price) === $this->toMinor((string) $data['asset_price'])
            && $this->toMinor((string) $existing->monthly_amount) === $this->toMinor((string) $data['monthly_amount'])
            && $existing->start_date->toDateString() === $data['start_date']
            && $existing->description === $this->blankToNull($data['description'] ?? null);
    }

    private function scopeToActorBranches(Builder $query, User $actor): void
    {
        if (! $actor->hasRole('super-admin')) {
            $query->whereIn('branch_id', $actor->branches()->select('branches.id'));
        }
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function toMinor(string|int|float $amount): int
    {
        $value = trim((string) $amount);
        if (! preg_match('/^(\d{1,16})(?:\.(\d{1,2}))?$/', $value, $matches)) {
            throw ValidationException::withMessages(['amount' => 'Amount must be a non-negative value with at most two decimal places.']);
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    private function formatMinor(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
