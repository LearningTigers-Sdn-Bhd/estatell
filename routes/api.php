<?php

use App\Http\Controllers\Api\Kiosk\MeController;
use App\Http\Controllers\Api\Kiosk\PairController;
use Illuminate\Support\Facades\Route;

/*
 * Device and integration API, loaded from bootstrap/app.php with the `api` middleware group (no session, no CSRF).
 * Each family has its own prefix and its own scope: a kiosk token (`kiosk:*`) never reaches `/api/v1`, and the reverse.
 * See docs/28-api-access/01-api-tokens-and-kiosk-pairing.md.
 */
Route::prefix('kiosk/v1')->name('api.kiosk.v1.')->group(function () {
    Route::post('pair', PairController::class)->middleware('throttle:api-pair')->name('pair');

    Route::middleware(['api.token:kiosk:*', 'throttle:api-token'])->group(function () {
        Route::get('me', MeController::class)->name('me');
    });
});
