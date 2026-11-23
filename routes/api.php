<?php

use App\Http\Controllers\Widget\BroadcastAuthController;
use App\Http\Controllers\Widget\ConfigController;
use App\Http\Controllers\Widget\MessageController;
use App\Http\Controllers\Widget\SessionController;
use Illuminate\Support\Facades\Route;

// Stateless (api middleware group: no session, no CSRF). Served as /api/widget/*.
Route::prefix('widget')->group(function (): void {
    Route::get('config/{public_key}', ConfigController::class)
        ->middleware('throttle:widget-config');

    Route::post('sessions', SessionController::class)
        ->middleware('throttle:widget-sessions');

    Route::middleware(['auth:widget', 'widget.access'])->group(function (): void {
        Route::post('broadcasting/auth', BroadcastAuthController::class)
            ->middleware('throttle:widget-broadcast-auth');

        Route::post('conversations/{conversation:ulid}/messages', [MessageController::class, 'store'])
            ->middleware('throttle:widget-messages');
    });
});
