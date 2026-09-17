<?php

namespace App\Modules\Setup\Services;

use App\Modules\Setup\Repositories\CustomerRepository;

class CustomerService extends BusinessMasterService
{
    public function __construct(CustomerRepository $repository)
    {
        parent::__construct($repository);
    }
}