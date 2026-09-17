<?php

namespace App\Modules\Setup\Services;

use App\Modules\Setup\Repositories\FoodRepository;

class FoodService extends BusinessMasterService
{
    public function __construct(FoodRepository $repository)
    {
        parent::__construct($repository);
    }
}