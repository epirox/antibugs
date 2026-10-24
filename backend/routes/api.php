<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CommentController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\TaxonomyController;
use App\Http\Controllers\Api\V1\TicketController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');

        Route::middleware('auth:api')->group(function () {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('refresh', [AuthController::class, 'refresh']);
            Route::post('logout', [AuthController::class, 'logout']);
        });
    });

    Route::middleware('auth:api')->group(function () {
        // Taxonomía (lectura cacheada para todos los roles)
        Route::get('categories', [TaxonomyController::class, 'categories']);
        Route::get('priorities', [TaxonomyController::class, 'priorities']);

        // Tickets
        Route::get('tickets', [TicketController::class, 'index']);
        Route::post('tickets', [TicketController::class, 'store']);
        Route::get('tickets/{id}', [TicketController::class, 'show'])->whereNumber('id');
        Route::patch('tickets/{id}/status', [TicketController::class, 'updateStatus'])->whereNumber('id');
        Route::post('tickets/{ticket}/comments', [CommentController::class, 'store']);

        // Staff: lista de colegas a quienes escalar un ticket (CU-T01)
        Route::get('technicians', [UserController::class, 'technicians'])->middleware('role:TECHNICIAN,ADMIN');

        // Administración
        Route::middleware('role:ADMIN')->group(function () {
            Route::apiResource('users', UserController::class)->except(['show']);

            Route::post('categories', [TaxonomyController::class, 'storeCategory']);
            Route::patch('categories/{category}', [TaxonomyController::class, 'updateCategory']);
            Route::post('priorities', [TaxonomyController::class, 'storePriority']);

            Route::get('reports/kpis', [ReportController::class, 'kpis']);
            Route::get('reports/export', [ReportController::class, 'export']);
        });
    });
});
