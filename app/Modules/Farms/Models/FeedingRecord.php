<?php

namespace App\Modules\Farms\Models;

use App\Models\User;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryConfirmation;
use App\Modules\Inventory\Models\Item;
use App\Modules\Setup\Models\Branch;
use App\Modules\Setup\Models\FarmInformation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class FeedingRecord extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_REJECTED = 'rejected';

    protected $table = 'farm_feeding_records';

    protected $fillable = [
        'feeding_number',
        'feeding_date',
        'feeding_time',
        'branch_id',
        'farm_information_id',
        'animal_balance_id',
        'animal_item_id',
        'animal_id',
        'target_type',
        'animal_type',
        'breed',
        'animal_count',
        'location',
        'status',
        'notes',
        'created_by_id',
        'submitted_by_id',
        'submitted_at',
        'confirmed_by_id',
        'confirmed_at',
        'rejected_by_id',
        'rejected_at',
        'rejection_reason',
        'posting_batch_id',
        'version',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function farmInformation(): BelongsTo
    {
        return $this->belongsTo(FarmInformation::class);
    }

    public function animalBalance(): BelongsTo
    {
        return $this->belongsTo(InventoryBalance::class, 'animal_balance_id');
    }

    public function animalItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'animal_item_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FeedingLine::class, 'feeding_record_id');
    }

    public function confirmation(): HasOne
    {
        return $this->hasOne(InventoryConfirmation::class, 'source_id')
            ->where('source_module', 'farms')
            ->where('source_type', 'feeding');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    protected function casts(): array
    {
        return [
            'feeding_date' => 'date',
            'animal_count' => 'decimal:6',
            'submitted_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }
}