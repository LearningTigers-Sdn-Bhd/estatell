<?php

use App\Http\Controllers\Api\Booking\ContentController;
use App\Http\Controllers\Api\Booking\HoldController;
use App\Http\Controllers\Api\Booking\ManageBookingController;
use App\Http\Controllers\Api\Booking\StayController as BookingStayController;
use App\Http\Controllers\Api\Kiosk\BookingController;
use App\Http\Controllers\Api\Kiosk\CheckInController;
use App\Http\Controllers\Api\Kiosk\CheckOutController;
use App\Http\Controllers\Api\Kiosk\ConfigController;
use App\Http\Controllers\Api\Kiosk\KeyController;
use App\Http\Controllers\Api\Kiosk\MeController;
use App\Http\Controllers\Api\Kiosk\PairController;
use App\Http\Controllers\Api\Kiosk\RegistrationCardController;
use App\Http\Controllers\Api\Kiosk\StayController;
use App\Http\Controllers\Api\Kiosk\WalkInController;
use Illuminate\Support\Facades\Route;

/*
 * Device and integration API, loaded from bootstrap/app.php with the `api` middleware group (no session, no CSRF).
 * Each family has its own prefix and its own scope: a kiosk token (`kiosk:*`) never reaches `/api/v1`, and the reverse.
 * See docs/28-api-access/01-api-tokens.md.
 */
Route::prefix('kiosk/v1')->name('api.kiosk.v1.')->group(function () {
    Route::post('pair', PairController::class)->middleware('throttle:api-pair')->name('pair');

    Route::middleware(['api.token:kiosk:*', 'throttle:api-token'])->group(function () {
        Route::post('cards/lookup', [CheckOutController::class, 'lookup'])->name('cards.lookup');
        Route::get('stays/{id}/folio', [CheckOutController::class, 'folio'])->name('stays.folio');
        Route::get('me', MeController::class)->name('me');
        Route::get('config', ConfigController::class)->name('config');
        Route::get('stays/{id}/eligibility', [CheckInController::class, 'eligibility'])->name('stays.eligibility');
        Route::get('stays/{id}/key', [KeyController::class, 'pin'])->name('stays.key');
        Route::get('stays/{id}/registration-card', [RegistrationCardController::class, 'show'])->name('stays.registration-card');
        Route::post('bookings/find', [BookingController::class, 'find'])->name('bookings.find');

        Route::post('walk-in/quote', [WalkInController::class, 'quote'])->middleware('throttle:api-walk-in')->name('walk-in.quote');

        // Writes: a repeated `Idempotency-Key` gets the first answer again.
        Route::middleware('api.idempotent')->group(function () {
            Route::post('walk-in/book', [WalkInController::class, 'book'])->middleware('throttle:api-walk-in')->name('walk-in.book');
            Route::post('stays/{id}/check-out', [CheckOutController::class, 'checkOut'])->name('stays.check-out');
            Route::post('stays/{id}/identity', [StayController::class, 'identity'])->name('stays.identity');
            Route::post('stays/{id}/guest', [StayController::class, 'guest'])->name('stays.guest');
            Route::post('stays/{id}/cards', [KeyController::class, 'card'])->name('stays.cards');
            Route::post('stays/{id}/check-in', [CheckInController::class, 'checkIn'])->name('stays.check-in');
            Route::post('stays/{id}/registration-card/sign', [RegistrationCardController::class, 'sign'])->name('stays.registration-card.sign');
        });
    });
});

/*
 * The booking engine API. A route names its ability, and the reach check finds the property in the path.
 * See docs/29-booking-engine/02-api.md.
 */
Route::prefix('booking/v1')->name('api.booking.v1.')->group(function () {
    Route::get('properties', [ContentController::class, 'properties'])
        ->middleware(['api.token:booking:listing', 'throttle:api-token'])
        ->name('properties.index');

    Route::post('search', [BookingStayController::class, 'search'])
        ->middleware(['api.token:booking:search', 'throttle:api-token'])
        ->name('search');

    Route::get('sites/{slug}', [ContentController::class, 'site'])
        ->middleware(['api.token:booking:site', 'throttle:api-token', 'api.booking.reach'])
        ->name('sites.show');

    Route::middleware(['api.token:booking:content', 'throttle:api-token', 'api.booking.reach'])->prefix('properties/{code}')->group(function () {
        Route::get('/', [ContentController::class, 'property'])->name('properties.show');
        Route::get('room-types', [ContentController::class, 'roomTypes'])->name('properties.room-types');
        Route::get('calendar', [BookingStayController::class, 'calendar'])->name('properties.calendar');
        Route::post('offers', [BookingStayController::class, 'offers'])->name('properties.offers');
    });

    Route::post('properties/{code}/holds', [HoldController::class, 'store'])
        ->middleware(['api.token:booking:checkout', 'throttle:api-token', 'throttle:api-booking-holds', 'api.booking.reach', 'api.idempotent'])
        ->name('holds.store');

    Route::middleware(['api.token:booking:checkout', 'throttle:api-token'])->prefix('holds/{token}')->group(function () {
        Route::get('/', [HoldController::class, 'show'])->name('holds.show');
        Route::delete('/', [HoldController::class, 'destroy'])->name('holds.destroy');
        Route::post('payment', [HoldController::class, 'payment'])->middleware('api.idempotent')->name('holds.payment');
    });

    Route::middleware(['api.token:booking:checkout', 'throttle:api-token'])->prefix('bookings/{token}')->group(function () {
        Route::get('/', [ManageBookingController::class, 'show'])->name('bookings.show');
        Route::get('cancellation', [ManageBookingController::class, 'cancellation'])->name('bookings.cancellation');
        Route::post('cancellation', [ManageBookingController::class, 'cancel'])->middleware('api.idempotent')->name('bookings.cancel');
    });
});
