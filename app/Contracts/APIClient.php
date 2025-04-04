<?php

namespace App\Contracts;

use App\DTO\APIResponse;

interface APIClient
{
    public function callAPI($orderId): APIResponse;
}
