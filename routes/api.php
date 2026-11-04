<?php

use App\Http\Controllers\Api\Kiosk\BookingController;
use App\Http\Controllers\Api\Kiosk\CheckInController;
use App\Http\Controllers\Api\Kiosk\ConfigController;
use App\Http\Controllers\Api\Kiosk\MeController;
use App\Http\Controllers\Api\Kiosk\PairController;
use App\Http\Controllers\Api\Kiosk\RegistrationCardController;
use App\Http\Controllers\Api\Kiosk\StayController;
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
        Route::get('config', ConfigController::class)->name('config');
        Route::get('stays/{id}/eligibility', [CheckInController::class, 'eligibility'])->name('stays.eligibility');
        Route::get('stays/{id}/registration-card', [RegistrationCardController::class, 'show'])->name('stays.registration-card');
        Route::post('bookings/find', [BookingController::class, 'find'])->name('bookings.find');

        // Writes: a repeated `Idempotency-Key` gets the first answer again.
        Route::middleware('api.idempotent')->group(function () {
            Route::post('stays/{id}/identity', [StayController::class, 'identity'])->name('stays.identity');
            Route::post('stays/{id}/guest', [StayController::class, 'guest'])->name('stays.guest');
            Route::post('stays/{id}/check-in', [CheckInController::class, 'checkIn'])->name('stays.check-in');
            Route::post('stays/{id}/registration-card/sign', [RegistrationCardController::class, 'sign'])->name('stays.registration-card.sign');
        });
    });
});
