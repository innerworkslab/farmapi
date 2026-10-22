<?php

namespace App\Modules\Farms\Services;

use App\Models\User;
use App\Modules\Farms\Models\AnimalProfile;
use App\Modules\Farms\Models\FarmAlert;
use App\Modules\Farms\Models\FarmOperation;
use App\Modules\Farms\Models\FarmSetting;
use App\Modules\Farms\Repositories\FarmOperationRepository;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Services\InventoryItemResolver;
use App\Modules\Inventory\Services\ItemUomService;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Setup\Models\Inventory;
use App\Support\BranchAccess;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FarmOperationService
{
    public function __construct(private FarmOperationRepository $records, private StockMovementService $stock, private InventoryItemResolver $items) {}

    public function paginate(array $filters, User $actor)
    {
        return $this->records->visible($actor, $filters)->orderByDesc('activity_date')->orderByDesc('id')->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 15))));
    }

    public function find(FarmOperation $operation, User $actor): FarmOperation
    {
        return $this->records->visible($actor)->findOrFail($operation->id);
    }

    public function create(array $data, User $actor): FarmOperation
    {
        return DB::transaction(function () use ($data, $actor) {
            $this->activeFarm($data, $actor);
            $existing = FarmOperation::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                BranchAccess::authorize($actor, $existing->branch_id);
                if ($existing->created_by_id !== $actor->id || $existing->type !== $data['type'] || $existing->farm_information_id !== (int) $data['farm_information_id'] || array_diff_key($existing->payload, array_flip(['posted_lines', 'rejection_reason', 'follow_up_completed_at', 'follow_up_completed_by_id', 'follow_up_notes'])) != $data['payload'] || $existing->activity_date->toDateString() !== $data['activity_date'] || $existing->targets()->get(['animal_balance_id', 'quantity'])->toArray() != $data['targets']) {
                    $this->invalid('idempotency_key', 'This key is already used for a different operation.');
                }

                return $existing->load('targets');
            }
            $animals = $this->animals($data['targets'], (int) $data['farm_information_id'], $actor);
            $operation = FarmOperation::query()->create([
                'reference' => 'FARM-'.Str::uuid(), 'type' => $data['type'], 'branch_id' => $data['branch_id'],
                'farm_information_id' => $data['farm_information_id'], 'activity_date' => $data['activity_date'],
                'activity_time' => $data['activity_time'] ?? null, 'idempotency_key' => $data['idempotency_key'],
                'payload' => $data['payload'], 'created_by_id' => $actor->id,
            ]);
            foreach ($data['targets'] as $target) {
                $operation->targets()->create($target);
            }
            $this->validatePayload($operation, $animals, $actor);

            return $operation->load('targets');
        });
    }

    public function update(FarmOperation $operation, array $data, User $actor): FarmOperation
    {
        return DB::transaction(function () use ($operation, $data, $actor) {
            $operation = $this->records->lock($operation, $actor);
            if (! in_array($operation->workflow_state, ['draft', 'rejected'])) {
                $this->invalid('workflow_state', 'Only draft or rejected operations can be edited.');
            }
            if ((int) ($data['version'] ?? 0) !== (int) $operation->version) {
                $this->invalid('version', 'Reload the current operation before editing.');
            }
            if ($data['type'] !== $operation->type || $data['idempotency_key'] !== $operation->idempotency_key) {
                $this->invalid('type', 'Type and idempotency key are immutable.');
            }
            $this->activeFarm($data, $actor);
            $animals = $this->animals($data['targets'], (int) $data['farm_information_id'], $actor);
            $operation->fill(['branch_id' => $data['branch_id'], 'farm_information_id' => $data['farm_information_id'],
                'activity_date' => $data['activity_date'], 'activity_time' => $data['activity_time'] ?? null,
                'payload' => $data['payload'], 'workflow_state' => 'draft', 'version' => $operation->version + 1])->save();
            $operation->targets()->delete();
            foreach ($data['targets'] as $target) {
                $operation->targets()->withTrashed()->updateOrCreate(['animal_balance_id' => $target['animal_balance_id']], ['quantity' => $target['quantity'], 'deleted_at' => null]);
            }
            $operation->load('targets');
            $this->validatePayload($operation, $animals, $actor);

            return $operation;
        });
    }

    public function transition(FarmOperation $operation, string $action, User $actor, ?string $reason = null): FarmOperation
    {
        return DB::transaction(function () use ($operation, $action, $actor, $reason) {
            $operation = $this->records->lock($operation, $actor);
            if ($action === 'confirm' && $operation->workflow_state === 'confirmed') {
                return $operation;
            }
            if ($action === 'reject') {
                if ($operation->workflow_state !== 'submitted' || ! $reason) {
                    $this->invalid('reason', 'A submitted operation and rejection reason are required.');
                }
                $operation->payload = array_merge($operation->payload, ['rejection_reason' => $reason]);
                $operation->workflow_state = 'rejected';
            } else {
                if (($action === 'submit' && ! in_array($operation->workflow_state, ['draft', 'rejected'])) || ($action === 'confirm' && $operation->workflow_state !== 'submitted')) {
                    $this->invalid('workflow_state', 'Operation is not in the required workflow state.');
                }
                $this->activeFarm($operation->toArray(), $actor);
                $targets = $operation->targets->map(fn ($t) => ['animal_balance_id' => $t->animal_balance_id, 'quantity' => $t->quantity])->all();
                $animals = $this->animals($targets, $operation->farm_information_id, $actor);
                $this->validatePayload($operation, $animals, $actor);
                if ($action === 'confirm') {
                    $this->post($operation, $animals, $actor);
                    $operation->confirmed_by_id = $actor->id;
                    $operation->confirmed_at = now();
                }
                $operation->workflow_state = $action === 'confirm' ? 'confirmed' : 'submitted';
            }
            $operation->version++;
            $operation->save();
            if ($operation->type === 'mortality' && $action === 'confirm') {
                app(FarmReportService::class)->refreshMortalityAlerts($operation, $actor);
            }

            return $operation->refresh()->load('targets');
        });
    }

    public function reverse(FarmOperation $operation, string $reason, User $actor): FarmOperation
    {
        return DB::transaction(function () use ($operation, $reason, $actor) {
            $operation = $this->records->lock($operation, $actor);
            if ($operation->workflow_state !== 'confirmed' || ! $reason) {
                $this->invalid('reason', 'Only confirmed operations can be reversed, with a reason.');
            }
            $ids = $operation->targets->pluck('animal_balance_id')->all();
            if (FarmOperation::query()->where('workflow_state', 'confirmed')->where('type', '!=', 'correction')->where('id', '!=', $operation->id)->where(fn ($q) => $q->where('confirmed_at', '>', $operation->confirmed_at)->orWhere(fn ($same) => $same->where('confirmed_at', $operation->confirmed_at)->where('id', '>', $operation->id)))->whereHas('targets', fn ($q) => $q->whereIn('animal_balance_id', $ids))->exists()) {
                $this->invalid('operation', 'Reverse later animal operations first.');
            }
            $correction = FarmOperation::query()->create([
                'reference' => 'REV-'.Str::uuid(), 'type' => 'correction', 'branch_id' => $operation->branch_id,
                'farm_information_id' => $operation->farm_information_id, 'activity_date' => now()->toDateString(),
                'workflow_state' => 'confirmed', 'idempotency_key' => 'reverse-'.$operation->id,
                'payload' => ['reason' => $reason], 'created_by_id' => $actor->id, 'confirmed_by_id' => $actor->id,
                'confirmed_at' => now(), 'original_operation_id' => $operation->id,
            ]);
            $animals = $this->records->lockAnimals($ids, $operation->farm_information_id, $actor)->keyBy('id');
            foreach ($operation->targets as $target) {
                $animal = $animals->get($target->animal_balance_id);
                if (! $animal) {
                    $this->invalid('animal', 'The animal has moved; a location correction is required first.');
                }
                if ($operation->type === 'mortality') {
                    $this->stock->post($animal, $target->quantity, 'mortality_reversal', $correction->id, $actor, 'reverse-death-'.$target->id, ['original_operation_id' => $operation->id]);
                }
                $snapshot = $target->snapshot ?? [];
                if ($operation->type === 'mortality' && isset($snapshot['restrictions'])) {
                    $animal->fill($snapshot['restrictions']);
                    $restricted = (float) $animal->reserved_quantity + (float) $animal->quarantined_quantity + (float) $animal->damaged_quantity + (float) $animal->expired_quantity;
                    if ($restricted > (float) $animal->on_hand_quantity) {
                        $this->invalid('stock', 'Resolve changed stock restrictions before reversal.');
                    }
                    $animal->available_quantity = (float) $animal->on_hand_quantity - $restricted;
                    $animal->save();
                }
                if (isset($snapshot['profile'])) {
                    $animal->profile->fill($snapshot['profile'])->save();
                }
                $correction->targets()->create(['animal_balance_id' => $animal->id, 'quantity' => $target->quantity]);
            }
            if ($operation->type === 'medication') {
                foreach ($operation->payload['posted_lines'] ?? [] as $index => $posted) {
                    $balance = InventoryBalance::query()->lockForUpdate()->findOrFail($posted['balance_id']);
                    BranchAccess::locations($actor, (int) $operation->branch_id, $balance->inventory_id);
                    $this->stock->post($balance, $posted['quantity'], 'medication_reversal', $correction->id, $actor, 'reverse-medicine-'.$operation->id.'-'.$index);
                }
            }
            if ($operation->type === 'mortality') {
                FarmAlert::query()->where('farm_information_id', $operation->farm_information_id)->whereIn('animal_balance_id', $ids)->whereDate('alert_date', $operation->activity_date)->get()->each(function ($alert) use ($reason) {
                    $alert->details = array_merge($alert->details, ['requires_reassessment' => true, 'correction_reason' => $reason]);
                    $alert->save();
                });
            }
            $operation->workflow_state = 'reversed';
            $operation->version++;
            $operation->save();

            if ($operation->type === 'mortality') {
                app(FarmReportService::class)->reconcileMortalityAlerts($operation, $actor);
            }

            return $correction->load('targets');
        });
    }

    public function completeFollowUp(FarmOperation $operation, string $notes, User $actor): FarmOperation
    {
        return DB::transaction(function () use ($operation, $notes, $actor) {
            $operation = $this->records->lock($operation, $actor);
            if ($operation->workflow_state !== 'confirmed' || empty($operation->payload['follow_up_date'])) {
                $this->invalid('operation', 'A confirmed record with follow-up is required.');
            }
            $operation->payload = array_merge($operation->payload, ['follow_up_completed_at' => now()->toIso8601String(), 'follow_up_completed_by_id' => $actor->id, 'follow_up_notes' => $notes]);
            $operation->version++;
            $operation->save();

            return $operation;
        });
    }

    private function activeFarm(array $data, User $actor): void
    {
        BranchAccess::locations($actor, (int) $data['branch_id'], null, (int) $data['farm_information_id']);
        if (FarmSetting::query()->where('farm_information_id', $data['farm_information_id'])->where('status', 'inactive')->exists()) {
            $this->invalid('farm', 'Inactive farms cannot receive new operational records.');
        }
    }

    private function animals(array $targets, int $farmId, User $actor)
    {
        $animals = $this->records->lockAnimals(array_column($targets, 'animal_balance_id'), $farmId, $actor)->keyBy('id');
        if ($animals->count() !== count($targets)) {
            $this->invalid('targets', 'Every animal must belong to this authorized farm.');
        }
        foreach ($targets as $target) {
            $animal = $animals[$target['animal_balance_id']];
            if ((float) $animal->on_hand_quantity < $target['quantity'] || $animal->profile?->animal_state === 'dead') {
                $this->invalid('targets', 'Affected quantity exceeds the active animal count.');
            }
            if ($animal->item?->itemable?->tracking_type === 'individual' && (int) $target['quantity'] !== 1) {
                $this->invalid('targets', 'Individual operations must use exactly one animal.');
            }
            if (! $animal->profile) {
                AnimalProfile::query()->create(['animal_balance_id' => $animal->id, 'initial_quantity' => (int) $animal->on_hand_quantity]);
                $animal->load('profile');
            }
        }

        return $animals;
    }

    private function validatePayload(FarmOperation $operation, $animals, User $actor): void
    {
        $p = $operation->payload;
        if (($p['veterinarian_required'] ?? false) && empty($p['veterinarian_id'])) {
            $this->invalid('payload.veterinarian_id', 'A veterinarian is required for this record.');
        }
        if (! empty($p['veterinarian_id']) && ! User::query()->where('account_status', 'active')->whereHas('branches', fn ($q) => $q->where('branches.id', $operation->branch_id))->whereKey($p['veterinarian_id'])->exists()) {
            $this->invalid('payload.veterinarian_id', 'Select an active veterinarian assigned to this branch.');
        }
        if ($operation->type === 'medication') {
            $totals = [];
            foreach ($p['lines'] as $line) {
                $balance = $this->medicine($operation, $line, $actor);
                $totals[$balance->id] = ($totals[$balance->id] ?? 0) + (float) $line['quantity'];
                if ($totals[$balance->id] > (float) $balance->available_quantity) {
                    $this->invalid('quantity', 'Combined medicine lines exceed eligible stock.');
                }
            }
        }
    }

    private function medicine(FarmOperation $operation, array $line, User $actor): InventoryBalance
    {
        BranchAccess::locations($actor, (int) $operation->branch_id, (int) $line['inventory_id']);
        $inventory = Inventory::query()->findOrFail($line['inventory_id']);
        if ($inventory->status !== 'active') {
            $this->invalid('inventory_id', 'Medicine source inventory must be active.');
        }
        $resolved = $this->items->resolve('medicine', (int) $line['medicine_item_id']);
        if ((int) $resolved['stock_uom_id'] !== (int) $line['stock_uom_id']) {
            $this->invalid('stock_uom_id', 'Use the configured medicine stock UOM.');
        }
        if ($resolved['batch_tracking'] && empty($line['stock_lot_id'])) {
            $this->invalid('stock_lot_id', 'Medicine lot is required.');
        }
        if (! in_array((int) $line['dosage_uom_id'], [(int) $resolved['item']->purchase_uom_id, (int) $resolved['item']->usage_uom_id], true)) {
            $this->invalid('dosage_uom_id', 'Use the configured medicine purchase or usage UOM for dosage.');
        }
        $balance = InventoryBalance::query()->with('stockLot')->where('category', 'medicine')->where('branch_id', $operation->branch_id)
            ->where('inventory_id', $line['inventory_id'])->where('item_id', $line['medicine_item_id'])->where('stock_uom_id', $line['stock_uom_id'])
            ->where('location', $line['source_location'])->where('stock_lot_id', $line['stock_lot_id'] ?? null)->lockForUpdate()->first();
        if (! $balance) {
            $this->invalid('stock', 'Matching medicine stock was not found.');
        }
        $lot = $balance->stockLot;
        if ($lot && ($lot->lot_status !== 'available' || ($lot->expiry_date && $lot->expiry_date->toDateString() < max(now()->toDateString(), $operation->activity_date->toDateString())) || ($lot->cold_chain_required && $lot->cold_chain_status !== 'compliant'))) {
            $this->invalid('stock_lot_id', 'Expired, recalled, quarantined or cold-chain-ineligible medicine cannot be used.');
        }
        if ((float) $balance->available_quantity < (float) $line['quantity']) {
            $this->invalid('quantity', 'Insufficient eligible medicine.');
        }

        return $balance;
    }

    private function post(FarmOperation $operation, $animals, User $actor): void
    {
        foreach ($operation->targets as $target) {
            $animal = $animals[$target->animal_balance_id];
            $profile = $animal->profile;
            $target->snapshot = ['animal' => $animal->item?->itemable?->only(['code', 'name', 'tracking_type', 'ear_tag_rfid_number', 'batch_flock_number', 'type', 'breed']), 'location' => $animal->location, 'restrictions' => $animal->only(['quarantined_quantity', 'damaged_quantity', 'expired_quantity']), 'profile' => $profile->only(['death_quantity', 'affected_quantity', 'health_status', 'animal_state', 'weight', 'weight_uom', 'withdrawal_end_date'])];
            $target->save();
            if ($operation->type === 'mortality') {
                $this->stock->post($animal, -$target->quantity, 'mortality', $operation->id, $actor, 'mortality-'.$operation->id.'-'.$animal->id, ['activity_date' => $operation->activity_date->toDateString(), 'reason' => $operation->payload['reason']]);
                $profile->death_quantity += $target->quantity;
                $profile->affected_quantity = min($profile->affected_quantity, (int) $animal->on_hand_quantity);
                $profile->animal_state = (float) $animal->on_hand_quantity === 0.0 ? 'dead' : 'alive';
            } elseif ($operation->type === 'health') {
                $profile->health_status = $operation->payload['health_status'];
                $profile->affected_quantity = in_array($profile->health_status, ['healthy', 'recovered']) ? 0 : $target->quantity;
            } elseif ($operation->type === 'weight') {
                $profile->weight = $operation->payload['weight'];
                $profile->weight_uom = $operation->payload['weight_uom'] ?? 'kg';
            } elseif ($operation->type === 'medication') {
                $profile->health_status = 'under_treatment';
                $profile->affected_quantity = $target->quantity;
                $until = $operation->activity_date->copy()->addDays((int) ($operation->payload['withdrawal_days'] ?? 0));
                if (! $profile->withdrawal_end_date || $until->greaterThan($profile->withdrawal_end_date)) {
                    $profile->withdrawal_end_date = $until;
                }
            }
            $profile->save();
        }
        if ($operation->type === 'medication') {
            $posted = [];
            foreach ($operation->payload['lines'] as $index => $line) {
                $balance = $this->medicine($operation, $line, $actor);
                $master = $this->items->resolve('medicine', (int) $line['medicine_item_id'])['model'];
                $units = app(ItemUomService::class);
                $required = (float) $units->toStock($master, (string) BigDecimal::of((string) $line['dosage'])->multipliedBy($operation->targets->sum('quantity')), (int) $line['dosage_uom_id']);
                $cost = $units->consumptionCost($master, $line['quantity']);
                $this->stock->post($balance, -(float) $line['quantity'], 'medicine_consumption', $operation->id, $actor, 'medicine-'.$operation->id.'-'.$index, ['required_quantity' => $required, 'dosage' => $line['dosage'], 'variance' => (float) $line['quantity'] - $required, 'cost' => $cost, 'follow_up_date' => $operation->payload['follow_up_date'] ?? null]);
                $posted[] = ['medicine_item_id' => $line['medicine_item_id'], 'stock_uom_id' => $line['stock_uom_id'], 'balance_id' => $balance->id, 'quantity' => $line['quantity'], 'required_quantity' => $required, 'variance' => (float) $line['quantity'] - $required, 'cost' => $cost];
            }
            $operation->payload = array_merge($operation->payload, ['posted_lines' => $posted]);
        }
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
