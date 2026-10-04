<?php

use App\Http\Controllers\Account\NotificationPreferenceController;
use App\Http\Controllers\Account\ProfileController;
use App\Http\Controllers\Account\SecurityController;
use App\Http\Controllers\Account\SetPasswordController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('account', '/account/profile');
});

Route::middleware(['auth'])->prefix('account')->name('account.')->group(function () {
    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('set-password', [SetPasswordController::class, 'edit'])->name('set-password.edit');
    Route::put('set-password', [SetPasswordController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('set-password.update');
});

Route::middleware(['auth', 'verified'])->prefix('account')->name('account.')->group(function () {
    Route::get('notifications', [NotificationPreferenceController::class, 'edit'])->name('notifications.edit');
    Route::put('notifications/{type}/{channel}', [NotificationPreferenceController::class, 'update'])->name('notifications.update');

    Route::delete('profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('password.update');

    Route::delete('sessions', [SecurityController::class, 'logoutOtherDevices'])
        ->middleware('throttle:6,1')
        ->name('sessions.destroy');

    Route::inertia('appearance', 'account/appearance')->name('appearance.edit');
});
