<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;

class Authenticate
{
    public function handle($r, Closure $next)
    {
        return $next($r);
    }
}
