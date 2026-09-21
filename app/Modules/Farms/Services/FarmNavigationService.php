<?php

namespace App\Modules\Farms\Services;

use App\Models\User;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Setup\Models\Animal;
use App\Modules\Setup\Models\FarmInformation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FarmNavigationService
{
    /** @param array<string, mixed> $filters */
    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        $query = FarmInformation::query()
            ->with(['branch', 'currentAnimal', 'responsibleEmployee'])
            ->select('farm_information.*')
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $inner) use ($search): void {
                    $inner
                        ->where('farm_information.name', 'like', "%{$search}%")
                        ->orWhere('farm_information.house_barn', 'like', "%{$search}%")
                        ->orWhere('farm_information.pen_cage_pond', 'like', "%{$search}%");

                    $numericSearch = preg_replace('/\D+/', '', $search);

                    if ($numericSearch !== '') {
                        $inner->orWhere('farm_information.id', (int) $numericSearch);
                    }
                });
            })
            ->when($filters['branch_id'] ?? null, fn (Builder $query, string $branchId) => $query->where('farm_information.branch_id', $branchId))
            ->when($filters['animal_type'] ?? null, function (Builder $query, string $animalType): void {
                $query->whereHas('currentAnimal', fn (Builder $animal) => $animal->where('type', $animalType));
            })
            ->when($filters['farm_type'] ?? null, function (Builder $query, string $farmType): void {
                $query->whereHas('currentAnimal', fn (Builder $animal) => $animal->where('type', $farmType));
            })
            ->when($filters['status'] ?? null, function (Builder $query, string $status): void {
                if ($status !== 'active') {
                    $query->whereRaw('1 = 0');
                }
            });

        $this->applyAuthorizedBranchScope($query, $user);
        $this->addAnimalCounts($query);

        return $query
            ->orderBy('farm_information.name')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    /** @return array<string, mixed> */
    public function detail(User $user, FarmInformation $farm): array
    {
        $farm = $this->findAuthorizedFarm($user, $farm);

        return [
            'farm' => $farm,
            'summary' => $this->summaryFor($farm),
            'animals' => $this->animalQuery($farm)
                ->limit(25)
                ->get(),
        ];
    }

    /** @param array<string, mixed> $filters */
    public function paginateAnimals(User $user, FarmInformation $farm, array $filters): LengthAwarePaginator
    {
        $farm = $this->findAuthorizedFarm($user, $farm);

        return $this->filteredAnimalQuery($farm, $filters)
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    /** @return array<string, mixed> */
    public function animalViewSummary(User $user, FarmInformation $farm, array $filters = []): array
    {
        $farm = $this->findAuthorizedFarm($user, $farm);

        return [
            'farm' => $farm,
            'tabs' => $this->animalTabCounts($farm),
            'filter_options' => $this->animalFilterOptions($farm),
            'selected_filters' => [
                'view' => $filters['view'] ?? $filters['tracking_type'] ?? 'all',
                'search' => $filters['search'] ?? null,
                'animal_type' => $filters['animal_type'] ?? null,
                'breed' => $filters['breed'] ?? null,
                'location' => $filters['location'] ?? null,
                'health_status' => $filters['health_status'] ?? null,
                'life_stage' => $filters['life_stage'] ?? null,
            ],
        ];
    }

    public function animalDetail(User $user, FarmInformation $farm, InventoryBalance $animalBalance): InventoryBalance
    {
        $farm = $this->findAuthorizedFarm($user, $farm);

        $animal = $this->animalQuery($farm)
            ->where('inventory_balances.id', $animalBalance->id)
            ->first();

        if (! $animal) {
            throw new NotFoundHttpException('Animal record was not found for the selected farm.');
        }

        return $animal;
    }

    private function findAuthorizedFarm(User $user, FarmInformation $farm): FarmInformation
    {
        $authorizedFarm = $this->authorizedFarmQuery($user)
            ->with(['branch', 'currentAnimal', 'responsibleEmployee'])
            ->find($farm->id);

        if (! $authorizedFarm) {
            throw new NotFoundHttpException('Farm was not found or is outside your authorized branch scope.');
        }

        return $authorizedFarm;
    }

    private function authorizedFarmQuery(User $user): Builder
    {
        $query = FarmInformation::query();
        $this->applyAuthorizedBranchScope($query, $user);

        return $query;
    }

    private function applyAuthorizedBranchScope(Builder $query, User $user): void
    {
        $branchIds = $user->branches()->pluck('branches.id');

        if ($branchIds->isNotEmpty()) {
            $query->whereIn('farm_information.branch_id', $branchIds);
        }
    }

    private function addAnimalCounts(Builder $query): void
    {
        $animalBalances = $this->baseAnimalAggregateQuery()
            ->groupBy('inventory_balances.farm_information_id')
            ->selectRaw('inventory_balances.farm_information_id')
            ->selectRaw('SUM(inventory_balances.on_hand_quantity) as animal_count')
            ->selectRaw("COUNT(DISTINCT CASE WHEN animals.tracking_type = 'batch' THEN inventory_balances.item_id END) as active_batch_count")
            ->selectRaw("SUM(CASE WHEN animals.tracking_type = 'individual' THEN inventory_balances.on_hand_quantity ELSE 0 END) as individual_animal_count");

        $query
            ->leftJoinSub($animalBalances, 'farm_animal_counts', function ($join): void {
                $join->on('farm_animal_counts.farm_information_id', '=', 'farm_information.id');
            })
            ->addSelect([
                DB::raw('COALESCE(farm_animal_counts.animal_count, 0) as animal_count'),
                DB::raw('COALESCE(farm_animal_counts.active_batch_count, 0) as active_batch_count'),
                DB::raw('COALESCE(farm_animal_counts.individual_animal_count, 0) as individual_animal_count'),
            ]);
    }

    /** @return array<string, float|int> */
    private function summaryFor(FarmInformation $farm): array
    {
        $summary = $this->baseAnimalAggregateQuery()
            ->where('inventory_balances.farm_information_id', $farm->id)
            ->selectRaw('SUM(inventory_balances.on_hand_quantity) as total_animal_count')
            ->selectRaw("COUNT(DISTINCT CASE WHEN animals.tracking_type = 'batch' THEN inventory_balances.item_id END) as active_batch_count")
            ->selectRaw("SUM(CASE WHEN animals.tracking_type = 'individual' THEN inventory_balances.on_hand_quantity ELSE 0 END) as individual_animal_count")
            ->first();

        return [
            'total_animal_count' => (float) ($summary?->total_animal_count ?? 0),
            'active_batch_count' => (int) ($summary?->active_batch_count ?? 0),
            'individual_animal_count' => (float) ($summary?->individual_animal_count ?? 0),
        ];
    }

    /** @return array<string, int> */
    private function animalTabCounts(FarmInformation $farm): array
    {
        $summary = $this->baseAnimalAggregateQuery()
            ->where('inventory_balances.farm_information_id', $farm->id)
            ->selectRaw('COUNT(*) as all_records')
            ->selectRaw("COUNT(CASE WHEN animals.tracking_type = 'batch' THEN 1 END) as batch_records")
            ->selectRaw("COUNT(CASE WHEN animals.tracking_type = 'individual' THEN 1 END) as individual_records")
            ->first();

        return [
            'all' => (int) ($summary?->all_records ?? 0),
            'batch' => (int) ($summary?->batch_records ?? 0),
            'individual' => (int) ($summary?->individual_records ?? 0),
        ];
    }

    /** @return array<string, list<string>> */
    private function animalFilterOptions(FarmInformation $farm): array
    {
        $rows = $this->animalQuery($farm)
            ->select([
                'animals.type as animal_type',
                'animals.breed',
                'inventory_balances.location',
            ])
            ->get();

        return [
            'views' => ['all', 'batch', 'individual'],
            'animal_types' => $this->uniqueValues($rows, 'animal_type'),
            'breeds' => $this->uniqueValues($rows, 'breed'),
            'locations' => $this->uniqueValues($rows, 'location'),
            'health_statuses' => ['healthy'],
            'life_stages' => [],
        ];
    }

    /** @param Collection<int, mixed> $rows */
    private function uniqueValues(Collection $rows, string $key): array
    {
        return $rows
            ->pluck($key)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $filters */
    private function filteredAnimalQuery(FarmInformation $farm, array $filters): Builder
    {
        $view = $filters['view'] ?? $filters['tracking_type'] ?? null;

        return $this->animalQuery($farm)
            ->when($view !== null && $view !== 'all', fn (Builder $query) => $query->where('animals.tracking_type', $view))
            ->when($filters['animal_type'] ?? null, fn (Builder $query, string $animalType) => $query->where('animals.type', $animalType))
            ->when($filters['breed'] ?? null, fn (Builder $query, string $breed) => $query->where('animals.breed', $breed))
            ->when($filters['location'] ?? null, fn (Builder $query, string $location) => $query->where('inventory_balances.location', $location))
            ->when($filters['health_status'] ?? null, function (Builder $query, string $healthStatus): void {
                if ($healthStatus !== 'healthy') {
                    $query->whereRaw('1 = 0');
                }
            })
            ->when($filters['life_stage'] ?? null, fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $inner) use ($search): void {
                    $inner
                        ->where('animals.code', 'like', "%{$search}%")
                        ->orWhere('animals.name', 'like', "%{$search}%")
                        ->orWhere('animals.batch_flock_number', 'like', "%{$search}%")
                        ->orWhere('animals.ear_tag_rfid_number', 'like', "%{$search}%");
                });
            });
    }

    private function baseAnimalAggregateQuery(): Builder
    {
        return InventoryBalance::query()
            ->join('items', 'items.id', '=', 'inventory_balances.item_id')
            ->leftJoin('animals', function ($join): void {
                $join
                    ->on('animals.id', '=', 'items.itemable_id')
                    ->where('items.itemable_type', Animal::class);
            })
            ->where('inventory_balances.category', 'animal')
            ->where('inventory_balances.on_hand_quantity', '>', 0)
            ->whereNotNull('inventory_balances.farm_information_id');
    }

    private function animalQuery(FarmInformation $farm): Builder
    {
        return InventoryBalance::query()
            ->with([
                'item' => fn ($query) => $query->with('itemable'),
                'inventory',
                'stockLot',
            ])
            ->join('items', 'items.id', '=', 'inventory_balances.item_id')
            ->leftJoin('animals', function ($join): void {
                $join
                    ->on('animals.id', '=', 'items.itemable_id')
                    ->where('items.itemable_type', Animal::class);
            })
            ->where('inventory_balances.category', 'animal')
            ->where('inventory_balances.farm_information_id', $farm->id)
            ->where('inventory_balances.on_hand_quantity', '>', 0)
            ->select('inventory_balances.*')
            ->orderByRaw("CASE WHEN animals.tracking_type = 'batch' THEN 0 ELSE 1 END")
            ->orderBy('items.name');
    }
}