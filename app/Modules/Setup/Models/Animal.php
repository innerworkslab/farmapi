<?php

namespace App\Modules\Setup\Models;

use App\Modules\Inventory\Models\Item;
use Database\Factories\AnimalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Animal extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $table = 'animals';

    protected $fillable = [
        'code',
        'tracking_type',
        'ear_tag_rfid_number',
        'batch_flock_number',
        'name',
        'animal_type_id',
        'animal_category_id',
        'animal_breed_id',
        'gender',
        'color_marking',
        'photo_path',
        'version',
    ];

    protected $with = ['animalType', 'animalCategory', 'animalBreed'];

    protected static function newFactory(): AnimalFactory
    {
        return AnimalFactory::new();
    }

    public function item(): MorphOne
    {
        return $this->morphOne(Item::class, 'itemable');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('setup')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return ['animal_type_id' => 'integer', 'animal_category_id' => 'integer', 'animal_breed_id' => 'integer'];
    }

    public function animalType(): BelongsTo
    {
        return $this->belongsTo(AnimalType::class);
    }

    public function animalCategory(): BelongsTo
    {
        return $this->belongsTo(AnimalCategory::class);
    }

    public function animalBreed(): BelongsTo
    {
        return $this->belongsTo(AnimalBreed::class);
    }

    public function classificationSnapshot(): array
    {
        return [
            'animal_type_id' => $this->animal_type_id,
            'animal_category_id' => $this->animal_category_id,
            'animal_breed_id' => $this->animal_breed_id,
            'animal_type' => ['id' => $this->animal_type_id, 'name' => $this->animalType?->name],
            'animal_category' => ['id' => $this->animal_category_id, 'name' => $this->animalCategory?->name],
            'animal_breed' => ['id' => $this->animal_breed_id, 'name' => $this->animalBreed?->name],
        ];
    }
}
