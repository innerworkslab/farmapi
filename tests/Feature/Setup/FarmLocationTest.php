<?php

namespace Tests\Feature\Setup;

use App\Models\User;
use App\Modules\Setup\Models\Branch;
use App\Modules\Setup\Models\FarmInformation;
use App\Modules\Setup\Models\HouseBarn;
use App\Modules\Setup\Models\PenCagePond;
use App\Modules\Setup\Services\FarmInformationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class FarmLocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_location_crud_and_normalized_names_scoped_to_branch(): void
    {
        [$branch, $other] = $this->fixture();
        foreach (['house-barns', 'pen-cage-ponds'] as $kind) {
            $body = ['name' => '  Location One  ', 'branch_id' => $branch->id];
            $id = $this->postJson('/api/v1/setup/'.$kind, $body)->assertCreated()
                ->assertJsonPath('data.name', 'Location One')->assertJsonMissingPath('data.normalized_name')->json('data.id');
            $this->postJson('/api/v1/setup/'.$kind, ['name' => 'LOCATION   ONE', 'branch_id' => $branch->id])->assertUnprocessable()->assertJsonValidationErrors('name');
            $this->postJson('/api/v1/setup/'.$kind, ['name' => 'Location One', 'branch_id' => $other->id])->assertCreated();
            $this->getJson('/api/v1/setup/'.$kind.'/'.$id)->assertOk()->assertJsonPath('data.id', $id);
            $this->getJson('/api/v1/setup/'.$kind.'?branch_id='.$branch->id.'&status=active')->assertOk()->assertJsonCount(1, 'data');
            $body['name'] = 'Renamed location';
            $this->postJson('/api/v1/setup/'.$kind.'/'.$id, $body)->assertOk()->assertJsonPath('data.name', 'Renamed location')->assertJsonPath('data.version', 2);
            $this->postJson('/api/v1/setup/'.$kind.'/'.$id.'/toggle-status')->assertOk()->assertJsonPath('data.status', 'inactive');
            $this->postJson('/api/v1/setup/'.$kind.'/'.$id.'/toggle-status')->assertOk()->assertJsonPath('data.status', 'active');
            $this->deleteJson('/api/v1/setup/'.$kind.'/'.$id)->assertOk()->assertJsonPath('message', 'Farm location deleted successfully.');
            $this->getJson('/api/v1/setup/'.$kind.'/'.$id)->assertNotFound();
        }
    }

    public function test_farm_entry_uses_matching_ids_and_supports_an_outdoor_pond(): void
    {
        [$branch, $other] = $this->fixture();
        [$barn, $pen] = $this->locations($branch);
        [$otherBarn, $otherPen] = $this->locations($other);
        $body = ['name' => 'Farm IDs', 'branch_id' => $branch->id, 'house_barn_id' => $barn->id, 'pen_cage_pond_id' => $pen->id];
        $id = $this->postJson('/api/v1/setup/farm-information', $body)->assertCreated()
            ->assertJsonPath('data.house_barn.id', $barn->id)->assertJsonPath('data.pen_cage_pond.id', $pen->id)->json('data.id');
        $this->assertFalse(Schema::hasColumn('farm_information', 'house_barn'));
        $this->assertFalse(Schema::hasColumn('farm_information', 'pen_cage_pond'));
        $this->postJson('/api/v1/setup/farm-information', array_replace($body, ['house_barn_id' => $otherBarn->id]))->assertUnprocessable()->assertJsonValidationErrors('house_barn_id');
        $this->postJson('/api/v1/setup/farm-information', array_replace($body, ['pen_cage_pond_id' => $otherPen->id]))->assertUnprocessable()->assertJsonValidationErrors('pen_cage_pond_id');
        $second = HouseBarn::create(['branch_id' => $branch->id, 'name' => 'Second', 'normalized_name' => 'second']);
        $this->postJson('/api/v1/setup/farm-information', array_replace($body, ['house_barn_id' => $second->id]))->assertUnprocessable()->assertJsonValidationErrors('pen_cage_pond_id');
        $this->postJson('/api/v1/setup/farm-information', $body + ['house_barn' => 'Barn', 'pen_cage_pond' => 'Pen'])->assertUnprocessable()->assertJsonValidationErrors(['house_barn', 'pen_cage_pond']);
        $this->postJson('/api/v1/setup/farm-information', ['name' => 'Missing', 'branch_id' => $branch->id])->assertUnprocessable()->assertJsonValidationErrors(['house_barn_id', 'pen_cage_pond_id']);
        $this->getJson('/api/v1/setup/farm-information?house_barn_id='.$barn->id)->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/setup/house-barns/'.$barn->id, ['name' => 'Renamed barn', 'branch_id' => $branch->id])->assertOk();
        $this->getJson('/api/v1/setup/farm-information/'.$id)->assertOk()->assertJsonPath('data.house_barn.name', 'Renamed barn');
        $this->getJson('/api/v1/setup/farm-information?search=Renamed%20barn')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/farms?search=Renamed%20barn')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.house_barn.id', $barn->id);
        $pond = PenCagePond::create(['branch_id' => $branch->id, 'name' => 'Outdoor pond', 'normalized_name' => 'outdoor pond']);
        $this->postJson('/api/v1/setup/farm-information/'.$id, array_replace($body, ['house_barn_id' => null, 'pen_cage_pond_id' => $pond->id]))->assertOk()
            ->assertJsonPath('data.house_barn', null)->assertJsonPath('data.pen_cage_pond.id', $pond->id);
        $this->getJson('/api/v1/setup/farm-information/location-options')->assertNotFound();
    }

    public function test_inactive_deleted_and_referenced_locations_are_protected(): void
    {
        [$branch] = $this->fixture();
        [$barn, $pen] = $this->locations($branch);
        $body = ['name' => 'Farm guarded', 'branch_id' => $branch->id, 'house_barn_id' => $barn->id, 'pen_cage_pond_id' => $pen->id];
        $id = $this->postJson('/api/v1/setup/farm-information', $body)->assertCreated()->json('data.id');
        foreach (['house-barns' => $barn, 'pen-cage-ponds' => $pen] as $kind => $record) {
            $this->deleteJson('/api/v1/setup/'.$kind.'/'.$record->id)->assertUnprocessable()->assertJsonValidationErrors('location');
            $this->postJson('/api/v1/setup/'.$kind.'/'.$record->id.'/toggle-status')->assertOk();
            $this->postJson('/api/v1/setup/farm-information/'.$id, $body)->assertUnprocessable();
            $this->postJson('/api/v1/setup/'.$kind.'/'.$record->id.'/toggle-status')->assertOk();
        }
        $second = HouseBarn::create(['branch_id' => $branch->id, 'name' => 'Second', 'normalized_name' => 'second']);
        $this->postJson('/api/v1/setup/pen-cage-ponds/'.$pen->id, ['name' => 'Moved pen', 'branch_id' => $branch->id, 'house_barn_id' => $second->id])->assertUnprocessable();
        $this->deleteJson('/api/v1/setup/farm-information/'.$id)->assertOk();
        $this->deleteJson('/api/v1/setup/pen-cage-ponds/'.$pen->id)->assertUnprocessable();
        $second->delete();
        $this->postJson('/api/v1/setup/farm-information', array_replace($body, ['house_barn_id' => $second->id]))->assertUnprocessable()->assertJsonValidationErrors('house_barn_id');
    }

    public function test_branch_scope_and_lookup_only_permissions(): void
    {
        [$branch, $other] = $this->fixture();
        [$barn] = $this->locations($other);
        $user = User::factory()->create(['account_status' => 'active']);
        $user->branches()->attach($branch->id);
        $user->givePermissionTo('setup.farm-information.create');
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/setup/house-barns')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/setup/house-barns/'.$barn->id)->assertForbidden();
        $this->postJson('/api/v1/setup/house-barns', ['name' => 'No write', 'branch_id' => $branch->id])->assertForbidden();
        $user->givePermissionTo('setup.house_barns.create');
        $this->postJson('/api/v1/setup/house-barns', ['name' => 'Wrong branch', 'branch_id' => $other->id])->assertForbidden();
    }

    public function test_database_rejects_cross_branch_location_foreign_keys(): void
    {
        [$branch, $other] = $this->fixture();
        [$barn] = $this->locations($other);
        $this->expectException(QueryException::class);
        FarmInformation::create(['name' => 'Invalid FK', 'branch_id' => $branch->id, 'house_barn_id' => $barn->id]);
    }

    public function test_service_revalidates_selections(): void
    {
        [$branch] = $this->fixture();
        [$barn, $pen] = $this->locations($branch);
        $barn->update(['status' => 'inactive']);
        $this->expectException(ValidationException::class);
        app(FarmInformationService::class)->create(['name' => 'Direct service', 'branch_id' => $branch->id, 'house_barn_id' => $barn->id, 'pen_cage_pond_id' => $pen->id]);
    }

    private function fixture(): array
    {
        $actor = User::factory()->create(['account_status' => 'active']);
        foreach (['house_barns', 'pen_cage_ponds', 'farm-information'] as $kind) {
            foreach (['view', 'create', 'update', 'delete'] as $action) {
                $name = 'setup.'.$kind.'.'.$action;
                Permission::findOrCreate($name, 'web');
                $actor->givePermissionTo($name);
            }
        }
        Permission::findOrCreate('farms.view', 'web');
        $actor->givePermissionTo('farms.view');
        $branches = [];
        foreach (['ONE', 'TWO'] as $code) {
            $branch = Branch::create(['code' => $code, 'name' => $code, 'status' => 'active']);
            $actor->branches()->attach($branch->id);
            $branches[] = $branch;
        }
        Sanctum::actingAs($actor);

        return $branches;
    }

    private function locations(Branch $branch): array
    {
        $barn = HouseBarn::create(['branch_id' => $branch->id, 'name' => 'Barn', 'normalized_name' => 'barn', 'status' => 'active']);
        $pen = PenCagePond::create(['branch_id' => $branch->id, 'house_barn_id' => $barn->id, 'name' => 'Pen', 'normalized_name' => 'pen', 'status' => 'active']);

        return [$barn, $pen];
    }
}
