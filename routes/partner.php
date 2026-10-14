<?php

use App\Http\Controllers\Partner\AcceptPartnerInvitationController;
use App\Http\Controllers\Partner\JoinController;
use App\Http\Controllers\Partner\PartnerPropertyController;
use App\Http\Controllers\Partner\PartnerSignupLinkController;
use Illuminate\Support\Facades\Route;

Route::prefix('partner-invitations')->name('partner-invitations.')->middleware('guest')->group(function () {
    Route::get('{token}', [AcceptPartnerInvitationController::class, 'show'])->name('show');
    Route::post('{token}', [AcceptPartnerInvitationController::class, 'store'])->middleware('throttle:6,1')->name('accept');
});

Route::get('join/{signupCode}', JoinController::class)->middleware('guest')->name('join');

Route::middleware(['auth', 'can:partner'])->prefix('partner')->name('partner.')->group(function () {
    Route::get('/', [PartnerPropertyController::class, 'index'])->name('properties.index');
    Route::post('properties', [PartnerPropertyController::class, 'store'])->middleware('support.2fa')->name('properties.store');
    Route::get('properties/{code}', [PartnerPropertyController::class, 'show'])->where('code', '[A-Za-z]{2}[0-9]{5,}')->name('properties.show');
    Route::get('properties/{code}/staff', [PartnerPropertyController::class, 'staff'])->where('code', '[A-Za-z]{2}[0-9]{5,}')->name('properties.staff');
    Route::get('properties/{code}/features', [PartnerPropertyController::class, 'features'])->where('code', '[A-Za-z]{2}[0-9]{5,}')->name('properties.features');
    Route::post('link', [PartnerSignupLinkController::class, 'regenerate'])->middleware('support.2fa')->name('link.regenerate');
});
