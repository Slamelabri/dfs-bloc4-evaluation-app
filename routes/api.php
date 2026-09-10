<?php

use App\Http\Controllers\Api\ExternalContextController;
use App\Http\Controllers\Api\TechnicianController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'service' => config('app.name'),
    'timestamp' => now()->toIso8601String(),
]));

// Webhook entrant, relaie par public/hooks.php.
// Declare cote API et non cote web : un appel machine-a-machine n'a ni session
// ni jeton CSRF. Sur routes/web.php le middleware VerifyCsrfToken renvoyait 419.
Route::post('webhooks/interventions', [WebhookController::class, 'handle'])
    ->middleware('throttle:60,1')
    ->name('webhooks.interventions');

Route::prefix('v1')
    ->middleware('api.token')
    ->group(function (): void {
        Route::apiResource('tickets', TicketController::class)->only([
            'index',
            'store',
            'show',
            'update',
        ]);
        Route::get('technicians', [TechnicianController::class, 'index']);
        Route::get('external/weather', [ExternalContextController::class, 'weather']);
    });
