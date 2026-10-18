<?php

use App\Http\Controllers\Portal\PortalController;
use App\Http\Controllers\Portal\PortalLinkController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('portal')->name('portal.')->group(function () {
    Route::get('/', [PortalController::class, 'index'])->name('index');

    Route::middleware('throttle:20,1')->prefix('links/{companyUser}')->name('links.')->group(function () {
        Route::post('accept', [PortalLinkController::class, 'accept'])->name('accept');
        Route::post('decline', [PortalLinkController::class, 'decline'])->name('decline');
    });

    Route::middleware('portal')->prefix('{property:code}')->where(['property' => '[A-Za-z]{2}[0-9]{5,}'])->group(function () {
        Route::get('/', [PortalController::class, 'show'])->name('show');
    });
});
