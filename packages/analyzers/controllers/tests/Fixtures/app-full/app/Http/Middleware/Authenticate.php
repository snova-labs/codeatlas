<?php
namespace App\Http\Middleware;
use Closure;
class Authenticate { public function handle($r, Closure $next) { return $next($r); } }
