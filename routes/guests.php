<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\Guests\GuestAnonymizeController;
use App\Http\Controllers\Guests\GuestController;
use App\Http\Controllers\Guests\GuestIdentityDocumentController;
use App\Http\Controllers\Guests\GuestMatchController;
use App\Http\Controllers\Guests\GuestMergeController;
use App\Http\Controllers\Guests\GuestNoteController;
use App\Http\Middleware\EnsureGuestIsNotMerged;
use Illuminate\Support\Facades\Route;

Route::prefix('guests')->name('guests.')->group(function () {
    Route::get('/', [GuestController::class, 'index'])->middleware('can:'.PermissionEnum::GuestsView->value)->name('index');

    Route::get('search', [GuestController::class, 'search'])->middleware('can:'.PermissionEnum::GuestsView->value)->name('search');

    Route::post('matches', GuestMatchController::class)->middleware('can:'.PermissionEnum::GuestsView->value)->name('matches');

    Route::middleware('can:'.PermissionEnum::GuestsManage->value)->group(function () {
        Route::post('/', [GuestController::class, 'store'])->name('store');
    });

    Route::scopeBindings()->prefix('{guest}')->middleware(EnsureGuestIsNotMerged::class)->group(function () {
        Route::middleware('can:'.PermissionEnum::GuestsView->value)->group(function () {
            Route::get('/', [GuestController::class, 'show'])->name('show');
            Route::get('notes', [GuestController::class, 'notes'])->name('notes.index');
        });

        Route::middleware('can:'.PermissionEnum::GuestsManage->value)->group(function () {
            Route::put('/', [GuestController::class, 'update'])->name('update');
            Route::post('vip', [GuestController::class, 'markVip'])->name('vip.store');
            Route::delete('vip', [GuestController::class, 'unmarkVip'])->name('vip.destroy');
            Route::post('notes', [GuestNoteController::class, 'store'])->name('notes.store');
        });

        Route::prefix('documents')->name('documents.')->middleware('can:'.PermissionEnum::GuestsViewDocuments->value)->group(function () {
            Route::get('/', [GuestController::class, 'documents'])->name('index');
            Route::get('{document}/scans/{scan}', [GuestIdentityDocumentController::class, 'scan'])->whereIn('scan', ['front', 'back'])->name('scan');
            Route::post('{document}/reveal', [GuestIdentityDocumentController::class, 'reveal'])->name('reveal');

            Route::middleware('can:'.PermissionEnum::GuestsManage->value)->group(function () {
                Route::post('/', [GuestIdentityDocumentController::class, 'store'])->name('store');
                Route::put('{document}', [GuestIdentityDocumentController::class, 'update'])->name('update');
                Route::delete('{document}', [GuestIdentityDocumentController::class, 'destroy'])->name('destroy');
            });
        });

        Route::post('anonymize', [GuestAnonymizeController::class, 'store'])->middleware('can:'.PermissionEnum::GuestsAnonymize->value)->name('anonymize.store');
        Route::post('merge', [GuestMergeController::class, 'store'])->middleware('can:'.PermissionEnum::GuestsMerge->value)->name('merge.store');

        Route::middleware('can:'.PermissionEnum::GuestsBlacklist->value)->group(function () {
            Route::post('blacklist', [GuestController::class, 'blacklist'])->name('blacklist.store');
            Route::delete('blacklist', [GuestController::class, 'unblacklist'])->name('blacklist.destroy');
        });
    });
});
