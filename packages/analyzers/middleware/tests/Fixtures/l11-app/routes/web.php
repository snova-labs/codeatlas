<?php

use Illuminate\Support\Facades\Route;

Route::get('/admin', fn () => 'ok')->middleware('admin:super');
Route::get('/profile', fn () => 'ok')->middleware(['track']);
