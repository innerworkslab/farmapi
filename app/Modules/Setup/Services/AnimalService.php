<?php

namespace App\Modules\Setup\Services;

use App\Modules\Setup\Repositories\AnimalRepository;

class AnimalService extends BusinessMasterService
{
    public function __construct(AnimalRepository $repository)
    {
        parent::__construct($repository);
    }
}