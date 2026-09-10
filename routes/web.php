<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('dashboard');

Route::post('/webhooks/interventions', [WebhookController::class, 'handle'])
    ->middleware('throttle:60,1')
    ->name('webhooks.interventions');
