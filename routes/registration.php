<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\Registration\GuestCardController;
use App\Http\Controllers\Registration\RegistrationCardPdfController;
use Illuminate\Support\Facades\Route;

Route::prefix('registration')->name('registration.')->group(function () {
    Route::middleware('can:'.PermissionEnum::RegistrationCardSign->value)->group(function () {
        Route::get('{unitGuest}', [GuestCardController::class, 'show'])->name('show');
        Route::put('{unitGuest}', [GuestCardController::class, 'update'])->name('update');
        Route::get('{unitGuest}/guest', [GuestCardController::class, 'guest'])->name('guest');
        Route::post('{unitGuest}/sign', [GuestCardController::class, 'sign'])->name('sign');
        Route::get('{unitGuest}/done', [GuestCardController::class, 'done'])->name('done');
        Route::get('{unitGuest}/pdf', [RegistrationCardPdfController::class, 'signed'])->name('pdf');
        Route::get('{unitGuest}/blank', [RegistrationCardPdfController::class, 'blank'])->name('blank');
    });

    Route::post('{unitGuest}/void', [GuestCardController::class, 'void'])
        ->middleware('can:'.PermissionEnum::RegistrationCardVoid->value)
        ->name('void');
});
