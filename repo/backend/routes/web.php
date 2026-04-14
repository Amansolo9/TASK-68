<?php

use Illuminate\Support\Facades\Route;

// Serve the Vue SPA for all non-API routes
Route::get('/{any}', function () {
    return view('app');
})->where('any', '.*');
