<?php

namespace App\Modules\Setup\Services;

use App\Modules\Setup\Repositories\UomRepository;

class UomService extends BusinessMasterService
{
    public function __construct(UomRepository $repository)
    {
        parent::__construct($repository);
    }
}