<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\Registration\GuestCardController;
use Illuminate\Support\Facades\Route;

Route::prefix('registration')->name('registration.')->group(function () {
    Route::middleware('can:'.PermissionEnum::RegistrationCardSign->value)->group(function () {
        Route::get('{unitGuest}', [GuestCardController::class, 'show'])->name('show');
        Route::post('{unitGuest}/sign', [GuestCardController::class, 'sign'])->name('sign');
    });

    Route::post('{unitGuest}/void', [GuestCardController::class, 'void'])
        ->middleware('can:'.PermissionEnum::RegistrationCardVoid->value)
        ->name('void');
});
