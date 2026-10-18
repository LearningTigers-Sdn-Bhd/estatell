<?php

use App\Http\Controllers\Portal\PortalBookController;
use App\Http\Controllers\Portal\PortalBookingController;
use App\Http\Controllers\Portal\PortalCompanyController;
use App\Http\Controllers\Portal\PortalController;
use App\Http\Controllers\Portal\PortalLinkController;
use App\Http\Controllers\Portal\PortalStatementController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('portal')->name('portal.')->group(function () {
    Route::get('/', [PortalController::class, 'index'])->name('index');
    Route::get('properties', [PortalController::class, 'properties'])->name('properties');

    Route::middleware('throttle:20,1')->prefix('links/{companyUser}')->name('links.')->group(function () {
        Route::post('accept', [PortalLinkController::class, 'accept'])->name('accept');
        Route::post('decline', [PortalLinkController::class, 'decline'])->name('decline');
    });

    Route::middleware('portal')->prefix('{property:code}')->where(['property' => '[A-Za-z]{2}[0-9]{5,}'])->group(function () {
        Route::get('/', [PortalController::class, 'show'])->name('show');

        Route::prefix('bookings')->name('bookings.')->group(function () {
            Route::get('/', [PortalBookingController::class, 'index'])->name('index');
            Route::get('new', [PortalBookController::class, 'create'])->name('create');
            Route::get('{reservation}', [PortalBookingController::class, 'show'])->whereNumber('reservation')->name('show');
            Route::put('{reservation}/guests', [PortalBookingController::class, 'updateGuests'])->whereNumber('reservation')->name('guests.update');
            Route::middleware('throttle:20,1')->group(function () {
                Route::post('offers', [PortalBookController::class, 'offers'])->name('offers');
                Route::post('/', [PortalBookController::class, 'store'])->name('store');
                Route::post('{reservation}/cancel', [PortalBookingController::class, 'cancel'])->whereNumber('reservation')->name('cancel');
                Route::post('{reservation}/cancellation-request', [PortalBookingController::class, 'requestCancellation'])->whereNumber('reservation')->name('cancellation-request');
            });
        });

        Route::prefix('statements')->name('statements')->group(function () {
            Route::get('/', [PortalStatementController::class, 'show']);
            Route::get('pdf', [PortalStatementController::class, 'pdf'])->name('.pdf');
            Route::get('invoices/{invoice}/pdf', [PortalStatementController::class, 'invoicePdf'])->whereNumber('invoice')->name('.invoices.pdf');
            Route::get('payments/{payment}/receipt', [PortalStatementController::class, 'receipt'])->whereNumber('payment')->name('.payments.receipt');
        });

        Route::get('company', [PortalCompanyController::class, 'show'])->name('company');
        Route::put('company', [PortalCompanyController::class, 'update'])->name('company.update');
    });
});
