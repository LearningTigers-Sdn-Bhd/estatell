<?php

use App\Http\Controllers\Partner\PartnerPropertyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'can:partner'])->prefix('partner')->name('partner.')->group(function () {
    Route::get('/', [PartnerPropertyController::class, 'index'])->name('properties.index');
});
