<?php

use App\Http\Controllers\MetricsController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'api' => url('/api/v1'),
    'health' => url('/up'),
    'metrics' => url('/metrics'),
]));

// Scrapeado por Prometheus desde la red interna de Docker (no se expone vía NGINX).
Route::get('/metrics', MetricsController::class)
    ->middleware('throttle:60,1');
