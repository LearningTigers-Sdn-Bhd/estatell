<?php

use App\Http\Controllers\Onboarding\OwnerAccountSetupController;
use Illuminate\Support\Facades\Route;

Route::prefix('g/owners/{user}/setup')->name('guest.owner-setup.')->middleware(['guest', 'signed'])->group(function () {
    Route::get('/', [OwnerAccountSetupController::class, 'show'])->name('show');
    Route::post('/', [OwnerAccountSetupController::class, 'store'])->middleware('throttle:6,1')->name('store');
});
