<?php

use App\Http\Controllers\Partner\AcceptPartnerInvitationController;
use App\Http\Controllers\Partner\PartnerPropertyController;
use Illuminate\Support\Facades\Route;

Route::prefix('partner-invitations')->name('partner-invitations.')->middleware('guest')->group(function () {
    Route::get('{token}', [AcceptPartnerInvitationController::class, 'show'])->name('show');
    Route::post('{token}', [AcceptPartnerInvitationController::class, 'store'])->middleware('throttle:6,1')->name('accept');
});

Route::middleware(['auth', 'can:partner'])->prefix('partner')->name('partner.')->group(function () {
    Route::get('/', [PartnerPropertyController::class, 'index'])->name('properties.index');
});
