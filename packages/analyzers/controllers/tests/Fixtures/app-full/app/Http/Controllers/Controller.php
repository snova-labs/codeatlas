<?php

namespace App\Http\Controllers;

abstract class Controller
{
    protected function ok(): string
    {
        return 'ok';
    }
}
