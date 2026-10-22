<?php

namespace Tests\Feature\Setup;

use App\Models\User;
use App\Modules\Setup\Models\Animal;
use App\Modules\Setup\Models\AnimalType;
use App\Modules\Setup\Services\AnimalClassificationService;
use Database\Seeders\AnimalClassificationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AnimalClassificationTest extends TestCase
{
    use RefreshDatabase;

    public static function kinds(): array
    {
        return [['animal-types', 'animal_types'], ['animal-categories', 'animal_categories'], ['animal-breeds', 'animal_breeds']];
    }

    #[DataProvider('kinds')]
    public function test_each_classification_supports_crud_status_filters_and_reserved_names(string $kind, string $table): void
    {
        $this->authorizeAll();
        $payload = ['name' => '  Test   Value  '];
        if ($kind !== 'animal-types') {
            $payload['animal_type_id'] = $this->type('cattle')->id;
        }
        $base = '/api/v1/setup/'.$kind;
        $id = $this->postJson($base, $payload)->assertCreated()->assertJsonPath('data.name', 'Test Value')
            ->assertJsonPath('data.status', 'active')->assertJsonMissingPath('data.normalized_name')->json('data.id');
        $this->postJson($base, array_replace($payload, ['name' => 'TEST VALUE']))->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->getJson($base.'?search=Test&status=active&per_page=100')->assertOk()->assertJsonFragment(['id' => $id, 'name' => 'Test Value']);
        $this->getJson($base.'/'.$id)->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson($base.'/'.$id, array_replace($payload, ['name' => 'Renamed']))->assertOk()->assertJsonPath('data.name', 'Renamed');
        $this->postJson($base.'/'.$id.'/toggle-status')->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->getJson($base.'?status=active')->assertOk()->assertJsonMissing(['id' => $id, 'name' => 'Renamed']);
        $this->postJson($base.'/'.$id.'/toggle-status')->assertOk()->assertJsonPath('data.status', 'active');
        $this->deleteJson($base.'/'.$id)->assertOk()->assertJsonPath('message', 'Animal classification deleted successfully.');
        $this->assertSoftDeleted($table, ['id' => $id]);
        $this->getJson($base.'/'.$id)->assertNotFound();
        $this->postJson($base, array_replace($payload, ['name' => 'renamed']))->assertUnprocessable();
        $this->assertDatabaseHas('activity_log', ['subject_id' => $id, 'event' => 'deleted']);
    }

    public function test_categories_and_breeds_are_scoped_to_type_and_not_to_each_other(): void
    {
        $this->authorizeAll();
        $cattle = $this->type('cattle');
        $goat = $this->type('goat');
        foreach (['animal-categories', 'animal-breeds'] as $kind) {
            $first = $this->postJson('/api/v1/setup/'.$kind, ['name' => 'shared', 'animal_type_id' => $cattle->id])->assertCreated()->json('data.id');
            $second = $this->postJson('/api/v1/setup/'.$kind, ['name' => 'shared', 'animal_type_id' => $goat->id])->assertCreated()->json('data.id');
            $this->getJson('/api/v1/setup/'.$kind.'?animal_type_id='.$cattle->id.'&status=active')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first);
            $this->getJson('/api/v1/setup/'.$kind.'?animal_type_id='.$goat->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second);
            $this->postJson('/api/v1/setup/'.$kind, ['name' => 'orphan'])->assertUnprocessable()->assertJsonValidationErrors('animal_type_id');
        }
        $this->deleteJson('/api/v1/setup/animal-types/'.$cattle->id)->assertUnprocessable();
        $this->postJson('/api/v1/setup/animal-types/'.$cattle->id.'/toggle-status')->assertOk();
        foreach (['animal-categories', 'animal-breeds'] as $kind) {
            $this->getJson('/api/v1/setup/'.$kind.'?animal_type_id='.$cattle->id.'&status=active')->assertOk()->assertJsonCount(0, 'data');
            $this->postJson('/api/v1/setup/'.$kind, ['name' => 'blocked', 'animal_type_id' => $cattle->id])->assertUnprocessable();
        }
    }

    public function test_animal_entry_requires_matching_active_ids_and_renames_preserve_current_labels(): void
    {
        $this->authorizeAll();
        [$type, $category, $breed] = $this->classificationSet('cattle');
        [$otherType, $otherCategory, $otherBreed] = $this->classificationSet('goat');
        $payload = ['code' => 'ANIMAL-IDS', 'tracking_type' => 'batch', 'batch_flock_number' => 'IDS-BATCH', 'name' => 'ID selected animal', 'animal_type_id' => $type->id, 'animal_category_id' => $category->id, 'animal_breed_id' => $breed->id, 'gender' => 'mixed'];
        $base = '/api/v1/setup/animals';
        $this->postJson($base, array_diff_key($payload, array_flip(['animal_type_id', 'animal_category_id', 'animal_breed_id'])) + ['type' => 'cattle', 'category' => 'beef', 'breed' => 'Brahman'])
            ->assertUnprocessable()->assertJsonValidationErrors(['animal_type_id', 'animal_category_id', 'animal_breed_id']);
        $this->postJson($base, array_replace($payload, ['animal_category_id' => $otherCategory->id]))->assertUnprocessable()->assertJsonValidationErrors('animal_category_id');
        $this->postJson($base, array_replace($payload, ['animal_breed_id' => $otherBreed->id]))->assertUnprocessable()->assertJsonValidationErrors('animal_breed_id');
        $this->postJson($base, array_replace($payload, ['animal_type_id' => ['invalid']]))->assertUnprocessable();
        $this->postJson('/api/v1/setup/animal-breeds/'.$breed->id.'/toggle-status')->assertOk();
        $this->postJson($base, $payload)->assertUnprocessable()->assertJsonValidationErrors('animal_breed_id');
        $this->postJson('/api/v1/setup/animal-breeds/'.$breed->id.'/toggle-status')->assertOk();
        $this->postJson($base, $payload + ['type' => 'spoof', 'category' => 'spoof', 'breed' => 'spoof'])->assertUnprocessable()->assertJsonValidationErrors(['type', 'category', 'breed']);
        $id = $this->postJson($base, $payload)->assertCreated()
            ->assertJsonMissingPath('data.type')->assertJsonMissingPath('data.category')->assertJsonMissingPath('data.breed')
            ->assertJsonPath('data.animal_type.name', 'cattle')->assertJsonPath('data.animal_category.name', 'beef')->assertJsonPath('data.animal_breed.name', 'Brahman')
            ->assertJsonPath('data.animal_type.id', $type->id)->json('data.id');
        $this->getJson($base.'?animal_type_id='.$type->id)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($base.'?type=cattle')->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->postJson($base.'/'.$id, array_replace($payload, ['animal_breed_id' => $otherBreed->id]))->assertUnprocessable();
        $this->postJson('/api/v1/setup/animal-types/'.$type->id, ['name' => 'Cattle revised'])->assertOk();
        $this->postJson('/api/v1/setup/animal-categories/'.$category->id, ['name' => 'Beef revised', 'animal_type_id' => $type->id])->assertOk();
        $this->postJson('/api/v1/setup/animal-breeds/'.$breed->id, ['name' => 'Brahman revised', 'animal_type_id' => $type->id])->assertOk();
        $this->getJson($base.'/'.$id)->assertOk()->assertJsonPath('data.animal_type.name', 'Cattle revised')->assertJsonPath('data.animal_category.name', 'Beef revised')->assertJsonPath('data.animal_breed.name', 'Brahman revised');
        $this->assertSame('Beef revised', Animal::findOrFail($id)->item->master_category);
        foreach (['animal-types' => $type, 'animal-categories' => $category, 'animal-breeds' => $breed] as $kind => $record) {
            $this->deleteJson('/api/v1/setup/'.$kind.'/'.$record->id)->assertUnprocessable();
        }
        $this->postJson('/api/v1/setup/animal-breeds/'.$breed->id, ['name' => 'Brahman revised', 'animal_type_id' => $otherType->id])->assertUnprocessable();
        $this->postJson('/api/v1/setup/animal-types/'.$type->id.'/toggle-status')->assertOk();
        $this->postJson($base, array_replace($payload, ['code' => 'NEW-ANIMAL', 'batch_flock_number' => 'NEW-BATCH']))->assertUnprocessable()->assertJsonValidationErrors('animal_type_id');
        $this->getJson($base.'/'.$id)->assertOk();
    }

    public function test_reference_apis_require_authentication_and_separate_write_permissions(): void
    {
        foreach (self::kinds() as [$kind]) {
            $this->getJson('/api/v1/setup/'.$kind)->assertUnauthorized();
        }
        $user = User::factory()->create(['account_status' => 'active']);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/setup/animal-types')->assertForbidden();
        Permission::findOrCreate('setup.animals.create', 'web');
        $user->givePermissionTo('setup.animals.create');
        foreach (self::kinds() as [$kind]) {
            $this->getJson('/api/v1/setup/'.$kind)->assertOk();
            $this->postJson('/api/v1/setup/'.$kind, ['name' => 'unauthorized'])->assertForbidden();
        }
        $user->update(['account_status' => 'inactive']);
        $this->getJson('/api/v1/setup/animal-types')->assertForbidden();
    }

    public function test_schema_and_seeder_use_only_required_reference_ids(): void
    {
        $this->seed(AnimalClassificationSeeder::class);
        $this->seed(AnimalClassificationSeeder::class);
        $this->assertDatabaseCount('animal_types', 1);
        $this->assertDatabaseCount('animal_categories', 1);
        $this->assertDatabaseCount('animal_breeds', 2);
        foreach (['type', 'category', 'breed'] as $column) {
            $this->assertFalse(Schema::hasColumn('animals', $column));
        }
        $this->expectException(QueryException::class);
        DB::table('animals')->insert(['code' => 'NO-REFERENCES', 'tracking_type' => 'batch', 'gender' => 'mixed']);
    }

    public function test_database_rejects_cross_type_references(): void
    {
        $animal = Animal::factory()->create();
        $otherType = $this->type('goat');
        $otherBreed = app(AnimalClassificationService::class)->save('animal-breeds', ['name' => 'Boer', 'animal_type_id' => $otherType->id]);
        $this->expectException(QueryException::class);
        DB::table('animals')->where('id', $animal->id)->update(['animal_breed_id' => $otherBreed->id]);
    }

    private function type(string $name): AnimalType
    {
        return app(AnimalClassificationService::class)->save('animal-types', ['name' => $name]);
    }

    private function classificationSet(string $name): array
    {
        $type = $this->type($name);
        $service = app(AnimalClassificationService::class);

        return [$type, $service->save('animal-categories', ['name' => 'beef', 'animal_type_id' => $type->id]), $service->save('animal-breeds', ['name' => 'Brahman', 'animal_type_id' => $type->id])];
    }

    private function authorizeAll(): void
    {
        $user = User::factory()->create(['account_status' => 'active']);
        foreach (['animals', 'animal_types', 'animal_categories', 'animal_breeds'] as $kind) {
            foreach (['view', 'create', 'update', 'delete'] as $action) {
                $permission = 'setup.'.$kind.'.'.$action;
                Permission::findOrCreate($permission, 'web');
                $user->givePermissionTo($permission);
            }
        }
        Sanctum::actingAs($user);
    }
}
