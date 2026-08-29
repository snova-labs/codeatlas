<?php

declare(strict_types=1);

namespace App\Http\Controllers;

abstract class Controller
{
    protected function ok(): string
    {
        return 'ok';
    }
}
