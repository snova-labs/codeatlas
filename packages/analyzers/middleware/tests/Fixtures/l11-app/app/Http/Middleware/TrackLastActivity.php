<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;

class TrackLastActivity
{
    public function handle($request, Closure $next)
    {
        return $next($request);
    }
}
