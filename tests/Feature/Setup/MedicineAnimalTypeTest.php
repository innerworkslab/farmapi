<?php

namespace Tests\Feature\Setup;

use App\Models\User;
use App\Modules\Setup\Models\AnimalType;
use App\Modules\Setup\Models\Medicine;
use App\Modules\Setup\Models\Uom;
use App\Modules\Setup\Services\AnimalClassificationService;
use App\Modules\Setup\Services\MedicineService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class MedicineAnimalTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_medicine_selection_create_update_filter_rename_and_clear(): void
    {
        [$payload, $cattle] = $this->fixture();
        $goat = app(AnimalClassificationService::class)->save('animal-types', ['name' => 'goat']);
        $id = $this->postJson('/api/v1/setup/medicines', $payload)->assertCreated()
            ->assertJsonPath('data.animal_type_id', $cattle->id)
            ->assertJsonPath('data.animal_type.name', 'cattle')
            ->assertJsonMissingPath('data.target_animal_type')
            ->assertJsonMissingPath('data.animal_type.normalized_name')->json('data.id');
        $this->assertFalse(Schema::hasColumn('medicines', 'target_animal_type'));
        $this->assertDatabaseHas('medicines', ['id' => $id, 'animal_type_id' => $cattle->id]);
        $this->getJson('/api/v1/setup/medicines?animal_type_id='.$goat->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/setup/medicines?animal_type_id='.$cattle->id)->assertOk()->assertJsonCount(1, 'data');
        $payload['animal_type_id'] = $goat->id;
        $this->postJson('/api/v1/setup/medicines/'.$id, $payload)->assertOk()->assertJsonPath('data.animal_type.name', 'goat');
        app(AnimalClassificationService::class)->save('animal-types', ['name' => 'Goats'], $goat->id);
        $this->getJson('/api/v1/setup/medicines/'.$id)->assertOk()->assertJsonPath('data.animal_type.name', 'Goats');
        $payload['animal_type_id'] = null;
        $this->postJson('/api/v1/setup/medicines/'.$id, $payload)->assertOk()
            ->assertJsonPath('data.animal_type_id', null)->assertJsonPath('data.animal_type', null);
        unset($payload['animal_type_id']);
        $payload['code'] = 'MED-NO-TARGET';
        $this->postJson('/api/v1/setup/medicines', $payload)->assertCreated()->assertJsonPath('data.animal_type', null);
    }

    public function test_invalid_inactive_deleted_and_text_selections_are_rejected(): void
    {
        [$payload, $type] = $this->fixture();
        foreach ([999999999, 'cattle'] as $invalid) {
            $this->postJson('/api/v1/setup/medicines', array_replace($payload, ['animal_type_id' => $invalid]))
                ->assertUnprocessable()->assertJsonValidationErrors('animal_type_id');
        }
        $this->postJson('/api/v1/setup/medicines', $payload + ['target_animal_type' => 'cattle'])
            ->assertUnprocessable()->assertJsonValidationErrors('target_animal_type');
        $id = $this->postJson('/api/v1/setup/medicines', $payload)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/setup/medicines/'.$id, $payload + ['target_animal_type' => 'cattle'])
            ->assertUnprocessable()->assertJsonValidationErrors('target_animal_type');
        $this->getJson('/api/v1/setup/medicines?target_animal_type=cattle')
            ->assertUnprocessable()->assertJsonValidationErrors('target_animal_type');
        $type->update(['status' => 'inactive']);
        $this->postJson('/api/v1/setup/medicines/'.$id, $payload)->assertUnprocessable()->assertJsonValidationErrors('animal_type_id');
        $this->getJson('/api/v1/setup/medicines/'.$id)->assertOk()->assertJsonPath('data.animal_type.status', 'inactive');
        $deleted = app(AnimalClassificationService::class)->save('animal-types', ['name' => 'deleted']);
        $deleted->delete();
        $this->postJson('/api/v1/setup/medicines', array_replace($payload, ['animal_type_id' => $deleted->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('animal_type_id');
    }

    public function test_service_rechecks_selection_inside_the_transaction(): void
    {
        [$payload, $type] = $this->fixture();
        $type->update(['status' => 'inactive']);
        $this->expectException(ValidationException::class);
        app(MedicineService::class)->create($payload);
    }

    public function test_referenced_type_cannot_be_deleted_even_after_medicine_soft_deletion(): void
    {
        [$payload, $type] = $this->fixture();
        $id = $this->postJson('/api/v1/setup/medicines', $payload)->assertCreated()->json('data.id');
        $actor = auth()->user();
        Permission::findOrCreate('setup.animal_types.delete', 'web');
        $actor->givePermissionTo('setup.animal_types.delete');
        $this->deleteJson('/api/v1/setup/animal-types/'.$type->id)->assertUnprocessable()->assertJsonValidationErrors('classification');
        $this->deleteJson('/api/v1/setup/medicines/'.$id)->assertOk();
        $this->deleteJson('/api/v1/setup/animal-types/'.$type->id)->assertUnprocessable()->assertJsonValidationErrors('classification');
        $this->assertNotNull(AnimalType::find($type->id));
    }

    public function test_database_foreign_key_restricts_physical_type_deletion(): void
    {
        [$payload, $type] = $this->fixture();
        $this->postJson('/api/v1/setup/medicines', $payload)->assertCreated();
        $this->expectException(QueryException::class);
        $type->forceDelete();
    }

    public function test_medicine_permissions_allow_type_lookup_but_not_classification_writes(): void
    {
        $this->fixture();
        $this->getJson('/api/v1/setup/animal-types?status=active')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/setup/animal-types', ['name' => 'goat'])->assertForbidden();
        $user = User::factory()->create(['account_status' => 'active']);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/setup/animal-types')->assertForbidden();
        $this->postJson('/api/v1/setup/medicines', [])->assertForbidden();
    }

    private function fixture(): array
    {
        $user = User::factory()->create(['account_status' => 'active']);
        foreach (['view', 'create', 'update', 'delete'] as $action) {
            $permission = 'setup.medicines.'.$action;
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);
        $type = app(AnimalClassificationService::class)->save('animal-types', ['name' => 'cattle']);
        $unit = Uom::create(['code' => 'DOSE', 'name' => 'Dose', 'symbol' => 'dose', 'category' => 'quantity', 'status' => 'active']);

        return [[
            'code' => 'MED-TARGET', 'name' => 'Targeted medicine', 'type' => 'vaccine', 'category' => 'injection',
            'animal_type_id' => $type->id, 'purchase_uom_id' => $unit->id, 'usage_uom_id' => $unit->id,
            'stock_uom_id' => $unit->id, 'uom_conversion' => 1, 'batch_tracking' => true,
            'expiry_tracking' => true, 'cold_chain_required' => false,
        ], $type];
    }
}
