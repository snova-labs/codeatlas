<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/users', [UserController::class, 'index'])->middleware('auth');
Route::get('/users/{id}', [UserController::class, 'show']);
Route::get('/health', HealthController::class);
