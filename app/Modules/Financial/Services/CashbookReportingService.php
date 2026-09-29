<?php

namespace App\Modules\Financial\Services;

use App\Models\User;
use App\Modules\Financial\Models\Cashbook;
use App\Modules\Financial\Models\CashbookLedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CashbookReportingService
{
    public function __construct(private readonly CashbookService $cashbooks) {}

    /** @return array<string, mixed> */
    public function dailySummary(Cashbook $cashbook, array $filters, User $actor): array
    {
        $cashbook = $this->cashbooks->find($cashbook, $actor);
        $from = CarbonImmutable::parse($filters['from_date'] ?? $filters['to_date'] ?? now()->toDateString())->toDateString();
        $to = CarbonImmutable::parse($filters['to_date'] ?? $filters['from_date'] ?? now()->toDateString())->toDateString();
        if ($from > $to) {
            throw ValidationException::withMessages(['from_date' => 'Start date must be on or before end date.']);
        }

        $before = $cashbook->entries()->where('source_type', '<>', 'opening_balance')->whereDate('entry_date', '<', $from)->get(['direction', 'amount']);
        $range = $cashbook->entries()->where('source_type', '<>', 'opening_balance')->whereDate('entry_date', '>=', $from)->whereDate('entry_date', '<=', $to)->get(['direction', 'amount']);
        $opening = $cashbook->effective_date->toDateString() <= $from
            ? $this->toMinor($cashbook->opening_balance)
            : 0;
        $opening += $this->netMinor($before);
        $totalIn = $this->sumDirection($range, 'in');
        $totalOut = $this->sumDirection($range, 'out');
        $closing = $opening + $totalIn - $totalOut;

        return [
            'cashbook_id' => $cashbook->id,
            'cashbook_name' => $cashbook->name,
            'currency_code' => $cashbook->currency_code,
            'from_date' => $from,
            'to_date' => $to,
            'opening_balance' => $this->formatMinor($opening),
            'total_in' => $this->formatMinor($totalIn),
            'total_out' => $this->formatMinor($totalOut),
            'closing_balance' => $this->formatMinor($closing),
        ];
    }

    /** @return array<string, mixed> */
    public function consolidated(array $filters, User $actor): array
    {
        $asOf = CarbonImmutable::parse($filters['as_of_date'] ?? now()->toDateString())->toDateString();
        $query = Cashbook::query()->with('branch');
        if (! $actor->hasRole('super-admin')) {
            $query->whereIn('branch_id', $actor->branches()->select('branches.id'));
        }
        $query->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['currency_code'] ?? null, fn ($q, $currency) => $q->where('currency_code', strtoupper($currency)));
        $books = $query->orderBy('branch_id')->orderBy('type')->orderBy('name')->get();
        if ($books->isEmpty()) {
            return ['as_of_date' => $asOf, 'currencies' => []];
        }

        $movementTotals = CashbookLedgerEntry::query()
            ->whereIn('cashbook_id', $books->modelKeys())
            ->where('source_type', '<>', 'opening_balance')
            ->whereDate('entry_date', '<=', $asOf)
            ->select('cashbook_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE 0 END), 0) as total_in")
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'out' THEN amount ELSE 0 END), 0) as total_out")
            ->groupBy('cashbook_id')
            ->get()
            ->keyBy('cashbook_id');

        $currencyGroups = $books->groupBy('currency_code')->map(function (Collection $currencyBooks, string $currency) use ($movementTotals, $asOf): array {
            $rows = $currencyBooks->map(function (Cashbook $book) use ($movementTotals, $asOf): array {
                $movement = $movementTotals->get($book->id);
                $opening = $book->effective_date->toDateString() <= $asOf ? $this->toMinor($book->opening_balance) : 0;
                $totalIn = $movement ? $this->toMinor($movement->total_in) : 0;
                $totalOut = $movement ? $this->toMinor($movement->total_out) : 0;

                return [
                    'cashbook_id' => $book->id,
                    'branch_id' => $book->branch_id,
                    'branch_name' => $book->branch->name,
                    'type' => $book->type,
                    'name' => $book->name,
                    'opening_balance' => $this->formatMinor($opening),
                    'total_in' => $this->formatMinor($totalIn),
                    'total_out' => $this->formatMinor($totalOut),
                    'closing_balance' => $this->formatMinor($opening + $totalIn - $totalOut),
                ];
            });

            return [
                'currency_code' => $currency,
                'total_opening_balance' => $this->formatMinor($rows->sum(fn (array $row) => $this->toMinor($row['opening_balance']))),
                'total_in' => $this->formatMinor($rows->sum(fn (array $row) => $this->toMinor($row['total_in']))),
                'total_out' => $this->formatMinor($rows->sum(fn (array $row) => $this->toMinor($row['total_out']))),
                'total_closing_balance' => $this->formatMinor($rows->sum(fn (array $row) => $this->toMinor($row['closing_balance']))),
                'cashbooks' => $rows->values()->all(),
            ];
        });

        return ['as_of_date' => $asOf, 'currencies' => $currencyGroups->values()->all()];
    }

    private function netMinor(Collection $entries): int
    {
        return $entries->sum(fn ($entry) => $entry->direction === 'in' ? $this->toMinor($entry->amount) : -$this->toMinor($entry->amount));
    }

    private function sumDirection(Collection $entries, string $direction): int
    {
        return $entries->where('direction', $direction)->sum(fn ($entry) => $this->toMinor($entry->amount));
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
        $negative = $minor < 0;
        $minor = abs($minor);

        return ($negative ? '-' : '').intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
