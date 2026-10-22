<?php

namespace Tests\Feature\Setup;

use App\Models\User;
use App\Modules\Setup\Models\Division;
use App\Modules\Setup\Models\Township;
use Database\Seeders\MyanmarGeographySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class GeographyApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MyanmarGeographySeeder::class);
    }

    public function test_both_lookups_are_public(): void
    {
        $response = $this->getJson('/api/v1/setup/divisions')->assertOk()->assertJsonCount(15, 'data');
        $response->assertJsonFragment(['id' => 15, 'name' => 'Yangon Region']);
        $names = array_column($response->json('data'), 'name');
        $sortedNames = $names;
        natcasesort($sortedNames);
        $this->assertSame(array_values($sortedNames), $names);
        $this->assertSame(['id', 'name'], array_keys($response->json('data.0')));

        $this->getJson('/api/v1/setup/divisions/15/townships')->assertOk()
            ->assertJsonFragment(['id' => 400, 'division_id' => 15, 'name' => 'Botataung']);
    }

    public function test_every_township_is_returned_only_under_its_source_division(): void
    {
        $divisions = File::json(database_path('seeders/data/myanmar/divisions.json'));
        $townships = collect(File::json(database_path('seeders/data/myanmar/townships.json')));
        $returnedIds = [];

        foreach ($divisions as $division) {
            $expected = $townships->where('division_id', $division['id'])
                ->whereStrict('is_active', true)->sortBy('id')
                ->map(fn (array $township): array => array_intersect_key($township, array_flip(['id', 'division_id', 'name'])))
                ->values()->all();
            $actual = $this->getJson('/api/v1/setup/divisions/'.$division['id'].'/townships')
                ->assertOk()->json('data');
            $this->assertSame($expected, collect($actual)->sortBy('id')->values()->all());
            $returnedIds = array_merge($returnedIds, array_column($actual, 'id'));
        }

        $this->assertCount(451, $returnedIds);
        $this->assertCount(451, array_unique($returnedIds));
    }

    public function test_endpoints_read_current_database_values_without_reading_json(): void
    {
        Division::findOrFail(15)->update(['name' => 'Updated Yangon Region']);
        Township::findOrFail(400)->update(['name' => 'Updated Botataung']);
        File::shouldReceive('json')->never();

        $this->getJson('/api/v1/setup/divisions')->assertOk()
            ->assertJsonFragment(['id' => 15, 'name' => 'Updated Yangon Region']);
        $this->getJson('/api/v1/setup/divisions/15/townships')->assertOk()
            ->assertJsonFragment(['id' => 400, 'division_id' => 15, 'name' => 'Updated Botataung']);
    }

    public function test_inactive_and_soft_deleted_records_are_excluded(): void
    {
        Township::findOrFail(400)->update(['status' => 'inactive']);
        Township::findOrFail(401)->delete();
        $rows = $this->getJson('/api/v1/setup/divisions/15/townships')->assertOk()->json('data');
        $this->assertNotContains(400, array_column($rows, 'id'));
        $this->assertNotContains(401, array_column($rows, 'id'));

        Division::findOrFail(15)->update(['status' => 'inactive']);
        Division::findOrFail(8)->delete();
        $this->getJson('/api/v1/setup/divisions')->assertOk()->assertJsonCount(13, 'data');
        $this->getJson('/api/v1/setup/divisions/15/townships')->assertNotFound();
        $this->getJson('/api/v1/setup/divisions/8/townships')->assertNotFound();
    }

    public function test_seeder_is_repeatable_and_preserves_status_deletion_and_extra_records(): void
    {
        Division::findOrFail(15)->update(['status' => 'inactive']);
        Township::findOrFail(400)->delete();
        $extra = Division::create(['name' => 'Additional reference region', 'status' => 'active']);
        $this->seed(MyanmarGeographySeeder::class);
        $this->seed(MyanmarGeographySeeder::class);

        $this->assertSame(16, Division::withTrashed()->count());
        $this->assertSame(451, Township::withTrashed()->count());
        $this->assertDatabaseHas('divisions', ['id' => 15, 'name' => 'Yangon Region', 'status' => 'inactive']);
        $this->assertDatabaseHas('divisions', ['id' => $extra->id, 'name' => $extra->name]);
        $this->assertSoftDeleted('townships', ['id' => 400]);
        $this->assertSame(15, Township::withTrashed()->findOrFail(400)->division->id);
    }

    public function test_unknown_or_malformed_division_is_not_found(): void
    {
        foreach (['99999', '0', 'abc', '-1', '1.5', '999999999999999999999999'] as $division) {
            $this->getJson("/api/v1/setup/divisions/{$division}/townships")->assertNotFound();
        }
    }

    public function test_lookup_access_does_not_depend_on_user_permissions_or_account_status(): void
    {
        $urls = ['/api/v1/setup/divisions', '/api/v1/setup/divisions/15/townships'];
        $user = User::factory()->create(['account_status' => 'active']);
        Sanctum::actingAs($user);
        foreach ($urls as $url) {
            $this->getJson($url)->assertOk();
        }

        $user->forceFill(['account_status' => 'inactive'])->save();
        foreach ($urls as $url) {
            $this->getJson($url)->assertOk();
        }
    }

    public function test_customer_endpoints_still_require_authentication(): void
    {
        $this->getJson('/api/v1/setup/customers')->assertUnauthorized();
        $this->postJson('/api/v1/setup/customers', [])->assertUnauthorized();
    }

    public function test_lookup_names_can_be_used_to_create_and_update_a_customer(): void
    {
        $user = $this->actingWithPermission('setup.customers.create');
        Permission::findOrCreate('setup.customers.update', 'web');
        $user->givePermissionTo('setup.customers.update');
        $division = collect($this->getJson('/api/v1/setup/divisions')->assertOk()->json('data'))->firstWhere('id', 15);
        $township = $this->getJson('/api/v1/setup/divisions/15/townships')->assertOk()->json('data.0');
        $payload = [
            'type' => 'retail', 'name' => 'Address dropdown customer', 'phone_number' => '+959700000001',
            'state_region' => $division['name'], 'township' => $township['name'],
        ];
        $id = $this->postJson('/api/v1/setup/customers', $payload)->assertCreated()
            ->assertJsonPath('data.state_region', $division['name'])
            ->assertJsonPath('data.township', $township['name'])->json('data.id');

        $division = collect($this->getJson('/api/v1/setup/divisions')->assertOk()->json('data'))->firstWhere('id', 8);
        $township = $this->getJson('/api/v1/setup/divisions/8/townships')->assertOk()->json('data.0');
        $this->postJson('/api/v1/setup/customers/'.$id, array_merge($payload, [
            'state_region' => $division['name'], 'township' => $township['name'],
        ]))->assertOk()->assertJsonPath('data.state_region', $division['name'])
            ->assertJsonPath('data.township', $township['name']);
    }

    private function actingWithPermission(string $permission): User
    {
        Permission::findOrCreate($permission, 'web');
        $user = User::factory()->create(['account_status' => 'active']);
        $user->givePermissionTo($permission);
        Sanctum::actingAs($user);

        return $user;
    }
}
