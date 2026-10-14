<?php

use App\Http\Controllers\WahaWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/webhooks/waha', WahaWebhookController::class)->name('webhooks.waha');
Route::post('/webhooks/waha/magang', WahaWebhookController::class);
