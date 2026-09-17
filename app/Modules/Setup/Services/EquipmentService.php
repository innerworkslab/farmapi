<?php

namespace App\Modules\Setup\Services;

use App\Modules\Setup\Repositories\EquipmentRepository;

class EquipmentService extends BusinessMasterService
{
    public function __construct(EquipmentRepository $repository)
    {
        parent::__construct($repository);
    }
}