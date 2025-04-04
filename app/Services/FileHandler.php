<?php

namespace App\Services;

class FileHandler
{
    public function open($filename, $mode)
    {
        return fopen($filename, $mode);
    }
}
