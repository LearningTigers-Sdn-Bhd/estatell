<?php

use App\Http\Controllers\AiAssistant\ConciergeChatController;
use App\Http\Controllers\Concierge\ConciergeController;
use App\Http\Controllers\Concierge\ConciergeDocumentController;
use App\Http\Controllers\Concierge\ConciergeEInvoiceController;
use App\Http\Controllers\Concierge\ConciergeGuideController;
use App\Http\Controllers\Concierge\ConciergeQuoteController;
use App\Http\Controllers\Concierge\ConciergeStayController;
use App\Http\Controllers\Concierge\ConciergeWalkInController;
use App\Http\Controllers\Property\PropertyLogoController;
use Illuminate\Support\Facades\Route;

/*
 * The Concierge page. No login: the lobby hub is public, a booking code opens a stay, and every stay page works from the
 * stay's own link. Both are guarded by `concierge` (the Concierge feature, a live property, a valid link) and throttled.
 */
Route::middleware(['concierge', 'throttle:concierge'])->group(function () {
    Route::get('stay/{property:code}', [ConciergeController::class, 'lobby'])->name('concierge.lobby');
    Route::post('stay/{property:code}', [ConciergeController::class, 'find'])->middleware('throttle:concierge-lookup')->name('concierge.find');

    // The logo is on the private disk and the staff route needs a login, so guests get it from here.
    Route::get('stay/{property:code}/logo', [PropertyLogoController::class, 'show'])->name('concierge.logo');
    Route::get('stay/{property:code}/find', [ConciergeGuideController::class, 'findForm'])->name('concierge.enter-code');
    Route::get('stay/{property:code}/contact', [ConciergeGuideController::class, 'contact'])->name('concierge.contact');
    Route::get('stay/{property:code}/info/{section?}', [ConciergeGuideController::class, 'info'])->where('section', 'property|amenities|policies|faqs')->name('concierge.info');
    Route::get('stay/{property:code}/nearby', [ConciergeGuideController::class, 'nearbyPlaces'])->name('concierge.nearby-places');
    Route::get('stay/{property:code}/walk-in', [ConciergeWalkInController::class, 'search'])->name('concierge.walk-in');
    Route::get('stay/{property:code}/walk-in/details', [ConciergeWalkInController::class, 'details'])->name('concierge.walk-in.details');
    Route::post('stay/{property:code}/walk-in', [ConciergeWalkInController::class, 'book'])->middleware('throttle:concierge-walk-in')->name('concierge.walk-in.book');
    Route::post('stay/{property:code}/walk-in/returning', [ConciergeWalkInController::class, 'returning'])->middleware('throttle:concierge-returning')->name('concierge.walk-in.returning');

    // A quote link the AI assistant sent. It needs the AI assistant feature and its RoomQuotes scope, not the walk-in switch.
    Route::middleware('ai-assistant:room-quotes')->group(function () {
        Route::get('stay/{property:code}/quote/{quote}', [ConciergeQuoteController::class, 'show'])->name('concierge.quote');
        Route::post('stay/{property:code}/quote/{quote}', [ConciergeQuoteController::class, 'book'])->middleware('throttle:concierge-walk-in')->name('concierge.quote.book');
        Route::post('stay/{property:code}/quote/{quote}/returning', [ConciergeQuoteController::class, 'returning'])->middleware('throttle:concierge-returning')->name('concierge.quote.returning');
    });

    Route::prefix('c/{token}')->name('concierge.')->group(function () {
        Route::get('/', [ConciergeController::class, 'show'])->name('show');
        Route::post('identity', [ConciergeController::class, 'identity'])->middleware('throttle:concierge-write')->name('identity');
        Route::post('sign', [ConciergeController::class, 'sign'])->middleware('throttle:concierge-write')->name('sign');
        Route::post('check-in', [ConciergeController::class, 'checkIn'])->middleware('throttle:concierge-write')->name('check-in');
        Route::get('requests', [ConciergeStayController::class, 'requests'])->name('requests');
        Route::post('requests', [ConciergeStayController::class, 'submitRequest'])->middleware('throttle:concierge-write')->name('requests.store');
        Route::get('info/{section?}', [ConciergeGuideController::class, 'stayInfo'])->where('section', 'property|amenities|policies|faqs|wifi')->name('stay-info');
        Route::get('contact', [ConciergeGuideController::class, 'stayContact'])->name('stay-contact');
        Route::get('amenities', [ConciergeStayController::class, 'amenities'])->name('amenities');
        Route::get('nearby', [ConciergeStayController::class, 'nearby'])->name('nearby');
        Route::get('bill', [ConciergeStayController::class, 'bill'])->name('bill');
        Route::get('documents/summary', [ConciergeDocumentController::class, 'summary'])->name('documents.summary');
        Route::get('documents/receipts/{payment}', [ConciergeDocumentController::class, 'receipt'])->whereNumber('payment')->name('documents.receipt');
        Route::get('documents/invoice', [ConciergeDocumentController::class, 'invoice'])->name('documents.invoice');
        Route::post('check-out', [ConciergeStayController::class, 'checkOut'])->middleware('throttle:concierge-write')->name('check-out');
        Route::post('check-out/ask', [ConciergeStayController::class, 'askCheckOut'])->middleware('throttle:concierge-write')->name('check-out.ask');
        Route::post('payment', [ConciergeController::class, 'pay'])->middleware('throttle:concierge-write')->name('payment');
        Route::post('payment/confirm', [ConciergeController::class, 'confirmPayment'])->middleware('throttle:concierge-write')->name('payment.confirm');
        Route::post('pin/retry', [ConciergeController::class, 'retryPin'])->middleware('throttle:concierge-write')->name('pin.retry');
    });
});

/*
 * A guest who has checked out may still ask for a refund or an e-invoice for a while. These are the writes that accept a
 * checked-out stay, so they have their own group with `concierge:checked-out`.
 */
Route::middleware(['concierge:checked-out', 'throttle:concierge'])->prefix('c/{token}')->name('concierge.')->group(function () {
    Route::post('refund', [ConciergeStayController::class, 'requestRefund'])->middleware('throttle:concierge-write')->name('refund');
    Route::get('e-invoice', [ConciergeEInvoiceController::class, 'show'])->name('e-invoice');
    Route::post('e-invoice', [ConciergeEInvoiceController::class, 'store'])->middleware('throttle:concierge-write')->name('e-invoice.store');
});

/*
 * The AI assistant chat on the Concierge page. It needs the AI assistant feature and its Concierge scope on top of the
 * Concierge guard. A message is limited per stay link, or per address and property in the lobby. See docs/34-ai-assistant/03-places-and-routes.md.
 */
Route::middleware(['concierge', 'ai-assistant:concierge', 'throttle:concierge'])->group(function () {
    Route::get('stay/{property:code}/ai/messages', [ConciergeChatController::class, 'lobbyMessages'])->name('concierge.chat.lobby');
    Route::post('stay/{property:code}/ai/messages', [ConciergeChatController::class, 'lobbySend'])->middleware('throttle:concierge-chat')->name('concierge.chat.lobby.send');
    Route::get('c/{token}/ai/messages', [ConciergeChatController::class, 'stayMessages'])->name('concierge.chat.stay');
    Route::post('c/{token}/ai/messages', [ConciergeChatController::class, 'staySend'])->middleware('throttle:concierge-chat')->name('concierge.chat.stay.send');
});
