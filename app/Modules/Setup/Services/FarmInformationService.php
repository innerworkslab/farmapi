<?php

namespace App\Modules\Setup\Services;

use App\Modules\Setup\Repositories\FarmInformationRepository;

class FarmInformationService extends BusinessMasterService
{
    public function __construct(FarmInformationRepository $repository)
    {
        parent::__construct($repository);
    }
}