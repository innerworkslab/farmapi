<?php

namespace App\Modules\Setup\Services;

use App\Modules\Setup\Repositories\InventoryRepository;

class InventoryService extends BusinessMasterService
{
    public function __construct(InventoryRepository $repository)
    {
        parent::__construct($repository);
    }
}