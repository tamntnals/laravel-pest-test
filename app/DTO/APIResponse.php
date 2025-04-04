<?php

namespace App\DTO;

class APIResponse
{
    public $status;
    public $data;

    // Remove the Order type hint for the second parameter to allow passing an int (or another type).
    public function __construct($status, $data)
    {
        $this->status = $status;
        $this->data = $data;
    }
}
