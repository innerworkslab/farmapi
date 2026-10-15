<?php

namespace App\Modules\Setup\Services;

use App\Models\User;
use App\Modules\Setup\Models\FarmInformation;
use App\Modules\Setup\Repositories\FarmInformationRepository;
use App\Support\BranchAccess;

class FarmInformationService extends BusinessMasterService
{
    public function __construct(FarmInformationRepository $repository)
    {
        parent::__construct($repository);
    }

    public function locationOptions(array $filters, User $actor): array
    {
        $query = BranchAccess::scope(FarmInformation::query(), $actor);
        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }

        return [
            'barns' => (clone $query)->whereNotNull('house_barn')->where('house_barn', '<>', '')->distinct()->orderBy('house_barn')->pluck('house_barn')->values(),
            'pens_cages_ponds' => (clone $query)->whereNotNull('pen_cage_pond')->where('pen_cage_pond', '<>', '')->distinct()->orderBy('pen_cage_pond')->pluck('pen_cage_pond')->values(),
        ];
    }
}
