<?php

use App\Http\Controllers\Api\Ai\MessageController as AiMessageController;
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
use App\Http\Controllers\Api\Pos\InHouseGuestController;
use App\Http\Controllers\Api\Pos\OutletController;
use App\Http\Controllers\Api\Pos\RoomChargeController;
use App\Http\Middleware\EnsureAiChatApiActive;
use App\Http\Middleware\EnsurePosApiFeatureActive;
use App\Models\AiConnection;
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
        Route::post('pricing', [BookingStayController::class, 'pricing'])->name('properties.pricing');
    });

    Route::post('properties/{code}/holds', [HoldController::class, 'store'])
        ->middleware(['api.token:booking:checkout', 'throttle:api-token', 'throttle:api-booking-holds', 'api.booking.reach', 'api.idempotent'])
        ->name('holds.store');

    Route::middleware(['api.token:booking:checkout', 'throttle:api-token'])->prefix('holds/{token}')->group(function () {
        Route::get('/', [HoldController::class, 'show'])->name('holds.show');
        Route::delete('/', [HoldController::class, 'destroy'])->name('holds.destroy');
        Route::post('payment', [HoldController::class, 'payment'])->middleware('api.idempotent')->name('holds.payment');
    });

    // Only `BookingLookupLockout` limits this route. Guests share one microsite token, so `api-token` would let one guest
    // use up the limit of the property's whole site.
    Route::post('properties/{code}/bookings/find', [ManageBookingController::class, 'find'])
        ->middleware(['api.token:booking:checkout', 'api.booking.reach'])
        ->name('bookings.find');

    Route::middleware(['api.token:booking:checkout', 'throttle:api-token'])->prefix('bookings/{token}')->group(function () {
        Route::get('/', [ManageBookingController::class, 'show'])->name('bookings.show');
        Route::get('cancellation', [ManageBookingController::class, 'cancellation'])->name('bookings.cancellation');
        Route::post('cancellation', [ManageBookingController::class, 'cancel'])->middleware('api.idempotent')->name('bookings.cancel');
    });
});

/*
 * The inbound POS API. The token's property is the only property a request can see. See docs/33-pos/03-pos-api.md.
 */
Route::prefix('pos/v1')->name('api.pos.v1.')->middleware(['api.token:pos:*', 'throttle:api-token', EnsurePosApiFeatureActive::class])->group(function () {
    Route::get('outlets', [OutletController::class, 'index'])->name('outlets.index');
    Route::get('in-house-guests', [InHouseGuestController::class, 'index'])->name('in-house-guests.index');

    // Writes: a repeated `Idempotency-Key` gets the first answer again.
    Route::middleware('api.idempotent')->group(function () {
        Route::post('room-charges', [RoomChargeController::class, 'store'])->name('room-charges.store');
        Route::post('room-charges/{external_receipt_id}/void', [RoomChargeController::class, 'void'])->name('room-charges.void');
    });
});

/*
 * The chat API for n8n. The token's property is the only property a request can see. The idempotency key is optional.
 * See docs/35-ai-assistant/03-places-and-routes.md#chat-api-for-n8n.
 */
Route::prefix('ai/v1')->name('api.ai.v1.')->middleware(['api.token:'.AiConnection::TOKEN_ABILITY, 'throttle:api-token', EnsureAiChatApiActive::class])->group(function () {
    Route::post('messages', [AiMessageController::class, 'store'])->middleware('api.idempotent')->name('messages.store');
});
