<?php

namespace App\Modules\Purchasing\Services;

use App\Models\User;
use App\Modules\Farms\Models\AnimalProfile;
use App\Modules\Farms\Models\FarmSetting;
use App\Modules\Inventory\Models\EquipmentInstance;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\ItemRegistryService;
use App\Modules\Inventory\Services\StockIdentityService;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Purchasing\Models\PurchaseReceipt;
use App\Modules\Purchasing\Models\PurchaseReceiptLine;
use App\Modules\Setup\Models\Animal;
use App\Modules\Setup\Models\FarmInformation;
use App\Modules\Setup\Models\Inventory;
use App\Support\BranchAccess;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AssetReceivingService
{
    public function __construct(private StockMovementService $movements, private StockIdentityService $identities, private ItemRegistryService $registry) {}

    public function receive(PurchaseReceipt $receipt, PurchaseReceiptLine $line, User $actor): void
    {
        $quantity = (float) $line->accepted_quantity + (float) $line->accepted_foc_quantity;
        if ($quantity <= 0) {
            return;
        }
        if (floor($quantity) !== $quantity || floor((float) $line->accepted_quantity) !== (float) $line->accepted_quantity || floor((float) $line->accepted_foc_quantity) !== (float) $line->accepted_foc_quantity) {
            $this->invalid('quantity', 'Animals and equipment must be received as whole units.');
        }
        if ((float) $line->conversion_factor !== 1.0) {
            $this->invalid('conversion_factor', 'Animals and equipment must use a one-to-one count UOM.');
        }
        $invoice = $receipt->invoice;
        $farm = $line->target_farm_information_id ? FarmInformation::query()->findOrFail($line->target_farm_information_id) : null;
        if ($line->category === 'animal' && ! $farm) {
            $this->invalid('target_farm_information_id', 'Animal receiving requires a farm.');
        }
        if ($farm && FarmSetting::query()->where('farm_information_id', $farm->id)->where('status', 'inactive')->exists()) {
            $this->invalid('farm', 'Inactive farms cannot receive animals or equipment.');
        }
        $inventory = $line->target_inventory_id ? Inventory::query()->findOrFail($line->target_inventory_id) : Inventory::query()
            ->where('branch_id', $invoice->branch_id)->where('status', 'active')->where('type', 'animal')->first();
        if (! $inventory || $inventory->status !== 'active') {
            $this->invalid('target_inventory_id', 'Select an active compatible inventory for receiving.');
        }
        BranchAccess::locations($actor, (int) $invoice->branch_id, $inventory->id, $farm?->id);
        $allowed = $inventory->allowed_item_categories ?? [];
        if ($allowed !== [] && ! in_array($line->category, $allowed, true) && ! in_array($line->item->master_category, $allowed, true)) {
            $this->invalid('target_inventory_id', 'Inventory does not permit this item category.');
        }
        if (! $line->stock_uom_id) {
            $this->invalid('stock_uom_id', 'A stock count UOM is required.');
        }
        Item::query()->lockForUpdate()->findOrFail($line->item_id);
        $master = $line->item->itemable;
        if (! $master || $line->item->status !== 'active') {
            $this->invalid('item_id', 'An active master item is required.');
        }
        if ($line->category === 'animal') {
            $this->animals($receipt, $line, $master, $inventory, $farm, (int) $quantity, $actor);
        } else {
            $this->equipment($receipt, $line, $master, $inventory, $farm, (int) $quantity, $actor);
        }
    }

    private function animals(PurchaseReceipt $receipt, PurchaseReceiptLine $line, Animal $master, Inventory $inventory, FarmInformation $farm, int $quantity, User $actor): void
    {
        $metadata = $line->metadata ?? [];
        $rows = $metadata['animals'] ?? [];
        if ($master->tracking_type === 'individual') {
            if ($rows === []) {
                if ($quantity !== 1 || ! $master->ear_tag_rfid_number) {
                    $this->invalid('metadata.animals', 'One accepted RFID row is required for each individual animal.');
                }
                $rows = [['rfid' => $master->ear_tag_rfid_number, 'existing' => true, 'foc' => (float) $line->accepted_foc_quantity > 0]];
            }
            if (count($rows) !== $quantity || count(array_filter($rows, fn ($row) => (bool) ($row['foc'] ?? false))) !== (int) $line->accepted_foc_quantity) {
                $this->invalid('metadata.animals', 'Accepted RFID rows and paid/FOC counts must match the receipt.');
            }
        } else {
            $rows = [array_merge($metadata, ['existing' => empty($metadata['batch_number']), 'quantity' => $quantity])];
        }
        foreach ($rows as $index => $row) {
            $animal = $master;
            $identityField = $master->tracking_type === 'individual' ? 'ear_tag_rfid_number' : 'batch_flock_number';
            $identity = trim((string) ($row[$master->tracking_type === 'individual' ? 'rfid' : 'batch_number'] ?? $master->{$identityField}));
            if ($identity === '') {
                $this->invalid('metadata', 'Batch number or RFID is required.');
            }
            if (! ($row['existing'] ?? false)) {
                if (Animal::withTrashed()->where($identityField, $identity)->exists()) {
                    $this->invalid('metadata', 'The received animal identity already exists.');
                }
                $animal = Animal::query()->create([
                    'code' => 'RCV-'.Str::uuid(), 'tracking_type' => $master->tracking_type, $identityField => $identity,
                    'name' => $row['name'] ?? $master->name, 'type' => $master->type, 'category' => $master->category,
                    'breed' => $row['breed'] ?? $master->breed, 'gender' => $row['gender'] ?? $master->gender,
                    'color_marking' => $row['color_marking'] ?? $master->color_marking, 'photo_path' => $row['photo_path'] ?? null,
                ]);
            }
            $animal = Animal::query()->lockForUpdate()->findOrFail($animal->id);
            $item = $this->registry->syncFromMaster($animal);
            $existing = InventoryBalance::query()->where('category', 'animal')->where('item_id', $item->id)->lockForUpdate()->get();
            if ($animal->tracking_type === 'individual' && $existing->isNotEmpty()) {
                $this->invalid('rfid', 'This RFID has already been received.');
            }
            if ($existing->contains(fn ($b) => (float) $b->on_hand_quantity > 0 && ((int) $b->farm_information_id !== $farm->id || (int) $b->inventory_id !== $inventory->id || $b->location !== ($line->target_location ?: 'MAIN')))) {
                $this->invalid('farm', 'An animal identity cannot belong to multiple current locations.');
            }
            $balance = $this->balance($line, $item->id, $inventory, $farm, null);
            $count = $animal->tracking_type === 'individual' ? 1 : $quantity;
            $profile = AnimalProfile::query()->firstOrCreate(['animal_balance_id' => $balance->id], ['initial_quantity' => (int) $balance->on_hand_quantity]);
            $health = $row['health_status'] ?? 'healthy';
            if (! in_array($health, ['healthy', 'under_observation', 'sick', 'under_treatment', 'quarantine', 'critical', 'recovered'], true)) {
                $this->invalid('health_status', 'Unknown animal health status.');
            }
            $profile->animal_state = 'alive';
            $profile->initial_quantity += $count;
            $profile->health_status = $health;
            $profile->affected_quantity = in_array($health, ['healthy', 'recovered']) ? 0 : $count;
            $profile->date_of_birth = $row['date_of_birth'] ?? $profile->date_of_birth;
            $profile->weight = $row['weight'] ?? $profile->weight;
            $profile->weight_uom = $row['weight_uom'] ?? $profile->weight_uom;
            $profile->life_stage = $row['life_stage'] ?? $profile->life_stage;
            $profile->bio_data = $row;
            $profile->save();
            $this->movements->post($balance, $count, 'animal_receipt', $receipt->id, $actor, 'animal-receipt-'.$line->id.'-'.$index,
                ['receipt_line_id' => $line->id, 'invoice_id' => $receipt->purchase_invoice_id, 'activity_date' => $receipt->receipt_date->toDateString(), 'paid_quantity' => $row['foc'] ?? false ? 0 : min($count, (int) $line->accepted_quantity), 'foc_quantity' => $row['foc'] ?? false ? 1 : ($animal->tracking_type === 'batch' ? (int) $line->accepted_foc_quantity : 0)]);
        }
    }

    private function equipment(PurchaseReceipt $receipt, PurchaseReceiptLine $line, $master, Inventory $inventory, ?FarmInformation $farm, int $quantity, User $actor): void
    {
        $units = $line->metadata['units'] ?? [];
        $serialized = (bool) $line->item->serial_tracking || $units !== [] || (($line->metadata['tracking_mode'] ?? 'asset') !== 'quantity');
        if (! $serialized) {
            $balance = $this->balance($line, $line->item_id, $inventory, $farm, null);
            $this->movements->post($balance, $quantity, 'equipment_receipt', $receipt->id, $actor, 'equipment-receipt-'.$line->id,
                ['paid_quantity' => $line->accepted_quantity, 'foc_quantity' => $line->accepted_foc_quantity]);

            return;
        }
        if ($units === [] && $quantity === 1 && $master->serial_number) {
            $units = [['serial_number' => $master->serial_number, 'foc' => (float) $line->accepted_foc_quantity > 0]];
        }
        if (count($units) !== $quantity || count(array_filter($units, fn ($u) => (bool) ($u['foc'] ?? false))) !== (int) $line->accepted_foc_quantity) {
            $this->invalid('metadata.units', 'Each accepted serial/asset must be listed with the correct paid/FOC flag.');
        }
        foreach ($units as $index => $unit) {
            $serial = trim((string) ($unit['serial_number'] ?? ''));
            $asset = trim((string) ($unit['asset_tag'] ?? ''));
            if ($serial === '' && $asset === '') {
                $this->invalid('metadata.units', 'Serial number or asset tag is required.');
            }
            if (($serial !== '' && EquipmentInstance::withTrashed()->where('serial_number', $serial)->exists()) || ($asset !== '' && EquipmentInstance::withTrashed()->where('asset_tag', $asset)->exists())) {
                $this->invalid('metadata.units', 'Serial number or asset tag has already been received.');
            }
            $instance = EquipmentInstance::query()->create([
                'equipment_id' => $master->id, 'branch_id' => $inventory->branch_id, 'inventory_id' => $inventory->id,
                'farm_information_id' => $farm?->id, 'location' => $line->target_location ?: 'MAIN',
                'serial_number' => $serial ?: null, 'asset_tag' => $asset ?: null,
                'condition' => $unit['condition'] ?? 'good', 'lifecycle_status' => $farm ? 'assigned' : 'available',
                'source_reference' => $receipt->receipt_number,
            ]);
            $balance = $this->balance($line, $line->item_id, $inventory, $farm, $instance->id);
            $this->movements->post($balance, 1, 'equipment_receipt', $receipt->id, $actor, 'equipment-receipt-'.$line->id.'-'.$index,
                ['receipt_line_id' => $line->id, 'foc' => (bool) ($unit['foc'] ?? false)]);
        }
    }

    private function balance(PurchaseReceiptLine $line, int $itemId, Inventory $inventory, ?FarmInformation $farm, ?int $instanceId): InventoryBalance
    {
        $identity = ['category' => $line->category, 'item_type' => $line->category, 'item_id' => $itemId,
            'branch_id' => $inventory->branch_id, 'inventory_id' => $inventory->id, 'farm_information_id' => $farm?->id,
            'location' => $line->target_location ?: 'MAIN', 'stock_uom_id' => $line->stock_uom_id,
            'stock_lot_id' => null, 'equipment_instance_id' => $instanceId];
        $balance = InventoryBalance::query()->firstOrCreate(['identity_key' => $this->identities->key($identity)], $identity);

        return InventoryBalance::query()->lockForUpdate()->findOrFail($balance->id);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
