<?php

namespace App\Modules\Farms\Services;

use App\Models\User;
use App\Modules\Farms\Models\FarmAlert;
use App\Modules\Farms\Models\FarmOperation;
use App\Modules\Farms\Models\FarmSetting;
use App\Modules\Farms\Models\FeedingPlan;
use App\Modules\Farms\Models\FeedingRecord;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryLedgerEntry;
use App\Modules\Inventory\Services\InventoryItemResolver;
use App\Modules\Setup\Models\FarmInformation;
use App\Support\BranchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FarmReportService
{
    public function farm(FarmInformation $farm, User $actor): FarmInformation
    {
        BranchAccess::authorize($actor, $farm->branch_id);

        return $farm;
    }

    public function settings(FarmInformation $farm, User $actor): array
    {
        $this->farm($farm, $actor);

        return FarmSetting::query()->where('farm_information_id', $farm->id)->first()?->toArray() ?? ['farm_information_id' => $farm->id, 'status' => 'active', 'timezone' => config('app.timezone'), 'daily_mortality_threshold' => null, 'cumulative_mortality_threshold' => null, 'feed_variance_threshold' => null, 'feed_wastage_threshold' => null, 'sickness_threshold' => null, 'expiry_warning_days' => 30];
    }

    public function saveSettings(FarmInformation $farm, array $data, User $actor): FarmSetting
    {
        $this->farm($farm, $actor);
        if (! empty($data['veterinarian_id']) && ! User::query()->findOrFail($data['veterinarian_id'])->branches()->whereKey($farm->branch_id)->exists()) {
            throw ValidationException::withMessages(['veterinarian_id' => 'The veterinarian must be assigned to this branch.']);
        }

        return FarmSetting::query()->updateOrCreate(['farm_information_id' => $farm->id], $data);
    }

    public function toggleStatus(FarmInformation $farm, User $actor): FarmSetting
    {
        return DB::transaction(function () use ($farm, $actor) {
            $this->farm($farm, $actor);
            FarmInformation::query()->whereKey($farm->id)->lockForUpdate()->firstOrFail();

            return $this->saveSettings($farm, ['status' => $this->settings($farm, $actor)['status'] === 'active' ? 'inactive' : 'active'], $actor);
        });
    }

    public function plans(FarmInformation $farm, array $filters, User $actor)
    {
        $this->farm($farm, $actor);

        return FeedingPlan::query()->where('farm_information_id', $farm->id)->when($filters['date'] ?? null, fn ($q, $date) => $q->whereDate('plan_date', $date))->orderByDesc('plan_date')->paginate($filters['per_page'] ?? 15);
    }

    public function notifications(User $actor)
    {
        return DB::table('farm_notifications')->join('farm_alerts', 'farm_alerts.id', '=', 'farm_notifications.alert_id')->where('recipient_id', $actor->id)->whereNull('farm_alerts.deleted_at')->whereIn('farm_alerts.farm_information_id', BranchAccess::scope(FarmInformation::query(), $actor)->select('id'))->select('farm_notifications.*', 'farm_alerts.type', 'farm_alerts.details')->orderByDesc('farm_notifications.id')->get();
    }

    public function savePlan(FarmInformation $farm, array $data, User $actor)
    {
        return DB::transaction(function () use ($farm, $data, $actor) {
            $this->farm($farm, $actor);
            if ($this->settings($farm, $actor)['status'] !== 'active') {
                throw ValidationException::withMessages(['farm' => 'Inactive farms cannot receive plans.']);
            }
            $animal = InventoryBalance::query()->where('category', 'animal')->where('farm_information_id', $farm->id)->where('on_hand_quantity', '>', 0)->lockForUpdate()->findOrFail($data['animal_balance_id']);
            $food = app(InventoryItemResolver::class)->resolve('food', (int) $data['food_item_id']);
            if ((int) $food['stock_uom_id'] !== (int) $data['stock_uom_id']) {
                throw ValidationException::withMessages(['stock_uom_id' => 'Use the food stock UOM.']);
            }
            $key = ['farm_information_id' => $farm->id, 'plan_date' => $data['plan_date'], 'animal_balance_id' => $animal->id, 'food_item_id' => $data['food_item_id'], 'stock_uom_id' => $data['stock_uom_id']];

            return FeedingPlan::query()->updateOrCreate($key, ['quantity' => $data['quantity'], 'created_by_id' => $actor->id]);
        });
    }

    private function feedingComparison(FarmInformation $farm, string $date, $feedings): array
    {
        $rows = [];
        foreach (FeedingPlan::query()->where('farm_information_id', $farm->id)->whereDate('plan_date', $date)->get() as $plan) {
            $key = $plan->animal_balance_id.':'.$plan->food_item_id.':'.$plan->stock_uom_id;
            $rows[$key] = ['animal_balance_id' => $plan->animal_balance_id, 'food_item_id' => $plan->food_item_id, 'stock_uom_id' => $plan->stock_uom_id, 'planned_quantity' => (float) $plan->quantity, 'actual_quantity' => 0, 'wastage_quantity' => 0];
        }
        foreach ($feedings as $feeding) {
            $ids = $feeding->targets->pluck('animal_balance_id')->push($feeding->animal_balance_id);
            foreach ($feeding->lines as $line) {
                foreach ($ids as $id) {
                    $key = $id.':'.$line->food_item_id.':'.$line->stock_uom_id;
                    $rows[$key] ??= ['animal_balance_id' => $id, 'food_item_id' => $line->food_item_id, 'stock_uom_id' => $line->stock_uom_id, 'planned_quantity' => null, 'actual_quantity' => 0, 'wastage_quantity' => 0];
                    $rows[$key]['actual_quantity'] += (float) $line->quantity / $ids->count();
                    $rows[$key]['wastage_quantity'] += (float) $line->wastage_quantity / $ids->count();
                }
            }
        }
        foreach ($rows as &$row) {
            $row['variance'] = $row['planned_quantity'] === null ? null : round($row['actual_quantity'] - $row['planned_quantity'], 6);
            $row['variance_percentage'] = $row['planned_quantity'] > 0 ? round($row['variance'] / $row['planned_quantity'] * 100, 4) : null;
            $row['wastage_percentage'] = $row['actual_quantity'] > 0 ? round($row['wastage_quantity'] / $row['actual_quantity'] * 100, 4) : 0;
        }

        return array_values($rows);
    }

    public function summary(FarmInformation $farm, User $actor, ?string $date = null): array
    {
        $this->farm($farm, $actor);
        $settings = $this->settings($farm, $actor);
        $date ??= now($settings['timezone'])->toDateString();
        $animals = InventoryBalance::query()->with('profile', 'item.itemable')->where('category', 'animal')->where('farm_information_id', $farm->id)->get();
        $profiles = $animals->pluck('profile')->filter();
        $initial = $animals->sum(fn ($a) => (float) ($a->profile?->initial_quantity ?? $a->on_hand_quantity));
        $deaths = $profiles->sum('death_quantity');
        $health = $animals->sum(fn ($a) => $a->profile && ! in_array($a->profile->health_status, ['healthy', 'recovered']) ? min((float) $a->on_hand_quantity, $a->profile->affected_quantity) : 0);
        $treatment = $animals->sum(fn ($a) => $a->profile?->health_status === 'under_treatment' ? min((float) $a->on_hand_quantity, $a->profile->affected_quantity) : 0);
        $feedings = FeedingRecord::query()->with('lines', 'targets')->where('farm_information_id', $farm->id)->where('status', 'confirmed')->whereDate('feeding_date', $date)->get();
        $medication = FarmOperation::query()->with('targets')->where('farm_information_id', $farm->id)->where('type', 'medication')->where('workflow_state', 'confirmed')->whereDate('activity_date', $date)->get();
        $dailyDeaths = FarmOperation::query()->where('farm_information_id', $farm->id)->where('type', 'mortality')->where('workflow_state', 'confirmed')->whereDate('activity_date', $date)->with('targets')->get()->sum(fn ($o) => $o->targets->sum('quantity'));
        $feedLines = $feedings->flatMap->lines;
        $medicineLines = $medication->flatMap(fn ($o) => $o->payload['posted_lines'] ?? []);
        $feedCosts = InventoryLedgerEntry::query()->where('source_type', 'feeding')->whereIn('source_id', $feedings->pluck('id'))->get()->sum(fn ($e) => (float) ($e->metadata['cost'] ?? 0));

        return [
            'date' => $date, 'timezone' => $settings['timezone'], 'total_animal_count' => $animals->sum('on_hand_quantity'),
            'active_batch_count' => $animals->filter(fn ($a) => (float) $a->on_hand_quantity > 0 && $a->item?->itemable?->tracking_type === 'batch')->count(),
            'individual_animal_count' => $animals->filter(fn ($a) => $a->item?->itemable?->tracking_type === 'individual')->sum('on_hand_quantity'),
            'healthy_animal_count' => max(0, $animals->sum('on_hand_quantity') - $health), 'sick_animal_count' => $health,
            'under_treatment_count' => $treatment, 'death_count' => $deaths, 'deaths_today' => $dailyDeaths,
            'mortality_rate' => $initial > 0 ? round($deaths / $initial * 100, 4) : 0,
            'feed_consumption_summary' => ['today_quantity' => $feedLines->sum('quantity'), 'today_wastage' => $feedLines->sum('wastage_quantity'),
                'today_cost' => number_format($feedCosts, 2, '.', ''), 'records_today' => $feedings->count(),
                'planned_actual_by_animal' => $this->feedingComparison($farm, $date, $feedings),
                'by_item' => $feedLines->groupBy('food_item_id')->map(fn ($lines) => ['quantity' => $lines->sum('quantity'), 'wastage' => $lines->sum('wastage_quantity')])->all()],
            'medicine_usage_summary' => ['today_quantity' => $medicineLines->sum('quantity'), 'today_cost' => number_format($medicineLines->sum(fn ($l) => (float) $l['cost']), 2, '.', ''),
                'records_today' => $medication->count(), 'animals_treated' => $medication->sum(fn ($o) => $o->targets->sum('quantity')),
                'medicines_by_item' => $medicineLines->groupBy('medicine_item_id')->map(fn ($lines) => ['quantity' => $lines->sum('quantity'), 'stock_uom_id' => $lines->first()['stock_uom_id']])->all(),
                'treatment_reasons' => $medication->map(fn ($o) => $o->payload['reason'])->unique()->values()->all(),
                'batches_treated' => $medication->flatMap->targets->pluck('animal_balance_id')->unique()->filter(fn ($id) => $animals->firstWhere('id', $id)?->item?->itemable?->tracking_type === 'batch')->count(),
                'animals_under_withdrawal' => $animals->filter(fn ($a) => $a->profile?->withdrawal_end_date && $a->profile->withdrawal_end_date->toDateString() >= $date)->sum('on_hand_quantity'),
                'new_sick_animals' => FarmOperation::query()->with('targets')->where('farm_information_id', $farm->id)->where('workflow_state', 'confirmed')->where('type', 'health')->whereDate('activity_date', $date)->get()->filter(fn ($o) => ! in_array($o->payload['health_status'], ['healthy', 'recovered']))->sum(fn ($o) => $o->targets->sum('quantity')),
                'follow_ups_due' => FarmOperation::query()->where('farm_information_id', $farm->id)->where('workflow_state', 'confirmed')->where('payload->follow_up_date', '<=', $date)->whereNull('payload->follow_up_completed_at')->count()],
        ];
    }

    public function timeline(FarmInformation $farm, array $filters, User $actor): array
    {
        $this->farm($farm, $actor);
        $events = collect();
        $operations = FarmOperation::query()->with('targets')->where('farm_information_id', $farm->id);
        if (! empty($filters['animal_balance_id'])) {
            $operations->whereHas('targets', fn ($q) => $q->where('animal_balance_id', $filters['animal_balance_id']));
        }
        foreach ($operations->get() as $op) {
            $events->push(['type' => $op->type, 'date' => $op->activity_date->toDateString(), 'time' => $op->activity_time,
                'reference' => $op->reference, 'record_id' => $op->id, 'workflow_state' => $op->workflow_state,
                'actor_id' => $op->created_by_id, 'confirmed_by_id' => $op->confirmed_by_id,
                'quantity' => $op->targets->sum('quantity'), 'payload' => $op->payload, 'targets' => $op->targets->toArray()]);
        }
        $feeding = FeedingRecord::query()->with('lines', 'targets')->where('farm_information_id', $farm->id);
        if (! empty($filters['animal_balance_id'])) {
            $feeding->where(fn ($q) => $q->where('animal_balance_id', $filters['animal_balance_id'])->orWhereIn('id', DB::table('farm_feeding_targets')->where('animal_balance_id', $filters['animal_balance_id'])->whereNull('deleted_at')->select('feeding_id')));
        }
        foreach ($feeding->get() as $f) {
            $events->push(['type' => 'feeding', 'date' => $f->feeding_date->toDateString(), 'time' => $f->feeding_time,
                'reference' => $f->feeding_number, 'record_id' => $f->id, 'workflow_state' => $f->status,
                'actor_id' => $f->created_by_id, 'confirmed_by_id' => $f->confirmed_by_id, 'quantity' => $f->lines->sum('quantity'), 'payload' => $f->toArray()]);
        }
        $movements = InventoryLedgerEntry::query()->where('farm_information_id', $farm->id)->whereIn('source_type', ['animal_receipt', 'equipment_receipt', 'transfer', 'sale', 'production']);
        if (! empty($filters['animal_balance_id'])) {
            $balance = InventoryBalance::query()->where('farm_information_id', $farm->id)->findOrFail($filters['animal_balance_id']);
            $movements->where('identity_key', $balance->identity_key);
        }
        foreach ($movements->get() as $m) {
            $events->push(['type' => str_contains($m->source_type, 'receipt') ? 'purchase' : $m->source_type,
                'date' => $m->metadata['activity_date'] ?? $m->posted_at->toDateString(), 'time' => $m->posted_at->format('H:i:s'),
                'reference' => $m->posting_id, 'record_id' => $m->source_id, 'workflow_state' => 'confirmed',
                'actor_id' => $m->posted_by_id, 'quantity' => (float) $m->quantity_in - (float) $m->quantity_out, 'payload' => $m->metadata]);
        }

        return $events->filter(fn ($e) => (empty($filters['type']) || $e['type'] === $filters['type']) && (empty($filters['from_date']) || $e['date'] >= $filters['from_date']) && (empty($filters['to_date']) || $e['date'] <= $filters['to_date']))
            ->sortByDesc(fn ($e) => $e['date'].' '.($e['time'] ?? '').' '.str_pad((string) $e['record_id'], 12, '0', STR_PAD_LEFT))->values()->all();
    }

    public function mortality(FarmInformation $farm, User $actor, ?string $date = null): array
    {
        $this->farm($farm, $actor);
        $settings = $this->settings($farm, $actor);
        $ops = FarmOperation::query()->with('targets')->where('farm_information_id', $farm->id)->where('type', 'mortality')->where('workflow_state', 'confirmed');
        if ($date) {
            $ops->whereDate('activity_date', $date);
        }
        $rows = [];
        foreach ($ops->get() as $op) {
            foreach ($op->targets as $target) {
                $key = $op->activity_date->toDateString().':'.$target->animal_balance_id;
                $rows[$key] ??= ['date' => $op->activity_date->toDateString(), 'animal_balance_id' => $target->animal_balance_id, 'death_quantity' => 0, 'causes' => []];
                $rows[$key]['death_quantity'] += $target->quantity;
                $rows[$key]['causes'][] = $op->payload['reason'];
            }
        }
        foreach ($rows as &$row) {
            $balance = InventoryBalance::query()->with('profile')->findOrFail($row['animal_balance_id']);
            $ledger = InventoryLedgerEntry::query()->where('identity_key', $balance->identity_key)->get();
            $before = (float) $balance->on_hand_quantity - $ledger->sum(fn ($m) => (float) $m->quantity_in - (float) $m->quantity_out) + $ledger->filter(fn ($m) => ($m->metadata['activity_date'] ?? $m->posted_at->toDateString()) < $row['date'])->sum(fn ($m) => (float) $m->quantity_in - (float) $m->quantity_out);
            $row['starting_quantity'] = max(0, $before);
            $row['daily_mortality_percentage'] = $before > 0 ? round($row['death_quantity'] / $before * 100, 4) : null;
            $row['current_quantity'] = (float) $balance->on_hand_quantity;
            $row['initial_quantity'] = $balance->profile?->initial_quantity ?? 0;
            $row['total_deaths'] = $balance->profile?->death_quantity ?? 0;
            $row['cumulative_mortality_percentage'] = $row['initial_quantity'] > 0 ? round($row['total_deaths'] / $row['initial_quantity'] * 100, 4) : null;
            $row['threshold_exceeded'] = ($settings['daily_mortality_threshold'] !== null && $row['daily_mortality_percentage'] !== null && $row['daily_mortality_percentage'] > (float) $settings['daily_mortality_threshold']) || ($settings['cumulative_mortality_threshold'] !== null && $row['cumulative_mortality_percentage'] > (float) $settings['cumulative_mortality_threshold']);
        }

        return array_values($rows);
    }

    public function refreshMortalityAlerts(FarmOperation $operation, User $actor): void
    {
        $farm = FarmInformation::query()->findOrFail($operation->farm_information_id);
        foreach ($this->mortality($farm, $actor, $operation->activity_date->toDateString()) as $row) {
            if (! $row['threshold_exceeded']) {
                continue;
            }
            $alert = FarmAlert::query()->firstOrCreate(['alert_key' => 'mortality-'.$farm->id.'-'.$row['animal_balance_id'].'-'.$row['date']],
                ['farm_information_id' => $farm->id, 'animal_balance_id' => $row['animal_balance_id'], 'type' => 'mortality', 'alert_date' => $row['date'], 'details' => $row]);
            $alert->details = array_merge($row, ['is_active' => true]);
            $alert->save();
            $settings = $this->settings($farm, $actor);
            foreach (array_filter(array_unique([$farm->responsible_employee_id, $settings['veterinarian_id'] ?? null])) as $recipient) {
                DB::table('farm_notifications')->updateOrInsert(['alert_id' => $alert->id, 'recipient_id' => $recipient], ['created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function reconcileMortalityAlerts(FarmOperation $operation, User $actor): void
    {
        $farm = FarmInformation::query()->findOrFail($operation->farm_information_id);
        $rows = collect($this->mortality($farm, $actor, $operation->activity_date->toDateString()))->keyBy('animal_balance_id');
        foreach (FarmAlert::query()->where('farm_information_id', $farm->id)->where('type', 'mortality')->whereDate('alert_date', $operation->activity_date)->get() as $alert) {
            $current = $rows->get($alert->animal_balance_id);
            $alert->details = array_merge($alert->details, $current ?? ['death_quantity' => 0, 'threshold_exceeded' => false], ['is_active' => (bool) ($current['threshold_exceeded'] ?? false), 'requires_reassessment' => false]);
            $alert->save();
        }
    }

    public function alerts(FarmInformation $farm, User $actor): array
    {
        $this->farm($farm, $actor);
        $alerts = FarmAlert::query()->where('farm_information_id', $farm->id)->orderByDesc('id')->get()->toArray();
        $today = now($this->settings($farm, $actor)['timezone'])->toDateString();
        $followups = FarmOperation::query()->where('farm_information_id', $farm->id)->where('workflow_state', 'confirmed')->whereNotNull('payload->follow_up_date')->where('payload->follow_up_date', '<=', $today)->whereNull('payload->follow_up_completed_at')->get();
        foreach ($followups as $op) {
            $alerts[] = ['type' => 'follow_up', 'operation_id' => $op->id, 'due_date' => $op->payload['follow_up_date'], 'reference' => $op->reference];
        }
        foreach (InventoryBalance::query()->with('profile')->where('farm_information_id', $farm->id)->where('category', 'animal')->get() as $animal) {
            if ($animal->profile?->withdrawal_end_date && $animal->profile->withdrawal_end_date->toDateString() >= $today) {
                $alerts[] = ['type' => 'withdrawal', 'animal_balance_id' => $animal->id, 'until' => $animal->profile->withdrawal_end_date->toDateString()];
            }
        }
        $settings = $this->settings($farm, $actor);
        $summary = $this->summary($farm, $actor, $today);
        foreach ($summary['feed_consumption_summary']['planned_actual_by_animal'] as $row) {
            if ($row['planned_quantity'] > 0 && $row['actual_quantity'] < $row['planned_quantity']) {
                $alerts[] = ['type' => 'feeding_incomplete', 'details' => $row];
            }
            if ($settings['feed_variance_threshold'] !== null && $row['variance_percentage'] !== null && abs($row['variance_percentage']) > (float) $settings['feed_variance_threshold']) {
                $alerts[] = ['type' => 'high_feed_variance', 'details' => $row];
            }
            if ($settings['feed_wastage_threshold'] !== null && $row['wastage_percentage'] > (float) $settings['feed_wastage_threshold']) {
                $alerts[] = ['type' => 'high_feed_wastage', 'details' => $row];
            }
        }
        if ($settings['sickness_threshold'] !== null && $summary['total_animal_count'] > 0 && $summary['medicine_usage_summary']['new_sick_animals'] / $summary['total_animal_count'] * 100 > (float) $settings['sickness_threshold']) {
            $alerts[] = ['type' => 'sickness_increase', 'quantity' => $summary['medicine_usage_summary']['new_sick_animals']];
        }
        foreach (FarmOperation::query()->where('farm_information_id', $farm->id)->where('type', 'medication')->whereIn('workflow_state', ['draft', 'submitted'])->whereDate('activity_date', '<=', $today)->get() as $op) {
            $alerts[] = ['type' => 'medication_due', 'operation_id' => $op->id, 'due_date' => $op->activity_date->toDateString()];
        }
        $stock = InventoryBalance::query()->with('item', 'stockLot')->where('branch_id', $farm->branch_id)->whereIn('category', ['food', 'medicine'])->where(fn ($q) => $q->whereNull('farm_information_id')->orWhere('farm_information_id', $farm->id))->get();
        foreach ($stock->groupBy(fn ($b) => $b->item_id.':'.$b->inventory_id.':'.$b->stock_uom_id) as $balances) {
            $first = $balances->first();
            $minimum = max((float) $first->item?->reorder_level, (float) $first->item?->minimum_stock_level);
            if ($minimum > 0 && $balances->sum('available_quantity') < $minimum) {
                $alerts[] = ['type' => $first->category === 'food' ? 'feed_stock_low' : 'medicine_stock_low', 'item_id' => $first->item_id, 'inventory_id' => $first->inventory_id, 'available_quantity' => $balances->sum('available_quantity'), 'threshold' => $minimum];
            }
        }
        $expiryCutoff = now($settings['timezone'])->addDays((int) $settings['expiry_warning_days'])->toDateString();
        foreach ($stock->where('category', 'medicine')->where('on_hand_quantity', '>', 0) as $balance) {
            if ($balance->stockLot?->expiry_date && $balance->stockLot->expiry_date->toDateString() <= $expiryCutoff) {
                $alerts[] = ['type' => 'medicine_expiry', 'stock_lot_id' => $balance->stock_lot_id, 'expiry_date' => $balance->stockLot->expiry_date->toDateString()];
            }
        }

        return $alerts;
    }

    public function investigate(FarmAlert $alert, string $notes, User $actor): FarmAlert
    {
        $this->farm(FarmInformation::query()->findOrFail($alert->farm_information_id), $actor);
        $alert->investigation = $notes;
        $alert->investigated_by_id = $actor->id;
        $alert->save();
        activity('farms')->performedOn($alert)->causedBy($actor)->withProperties(['notes' => $notes])->log('Mortality investigation recorded');

        return $alert;
    }
}
