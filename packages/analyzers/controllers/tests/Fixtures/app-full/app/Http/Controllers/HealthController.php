<?php

declare(strict_types=1);

namespace App\Http\Controllers;

class HealthController extends Controller
{
    public function __invoke(): string
    {
        return 'healthy';
    }
}
