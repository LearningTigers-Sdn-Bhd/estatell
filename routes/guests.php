<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\Guests\GuestController;
use App\Http\Controllers\Guests\GuestNoteController;
use Illuminate\Support\Facades\Route;

Route::prefix('guests')->name('guests.')->group(function () {
    Route::get('/', [GuestController::class, 'index'])->middleware('can:'.PermissionEnum::GuestsView->value)->name('index');

    Route::middleware('can:'.PermissionEnum::GuestsManage->value)->group(function () {
        Route::get('create', [GuestController::class, 'create'])->name('create');
        Route::post('/', [GuestController::class, 'store'])->name('store');
    });

    Route::scopeBindings()->prefix('{guest}')->group(function () {
        Route::middleware('can:'.PermissionEnum::GuestsView->value)->group(function () {
            Route::get('/', [GuestController::class, 'show'])->name('show');
            Route::get('notes', [GuestController::class, 'notes'])->name('notes.index');
        });

        Route::middleware('can:'.PermissionEnum::GuestsManage->value)->group(function () {
            Route::get('edit', [GuestController::class, 'edit'])->name('edit');
            Route::put('/', [GuestController::class, 'update'])->name('update');
            Route::post('vip', [GuestController::class, 'markVip'])->name('vip.store');
            Route::delete('vip', [GuestController::class, 'unmarkVip'])->name('vip.destroy');
            Route::post('notes', [GuestNoteController::class, 'store'])->name('notes.store');
        });

        Route::middleware('can:'.PermissionEnum::GuestsBlacklist->value)->group(function () {
            Route::post('blacklist', [GuestController::class, 'blacklist'])->name('blacklist.store');
            Route::delete('blacklist', [GuestController::class, 'unblacklist'])->name('blacklist.destroy');
        });
    });
});
