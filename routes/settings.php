<?php

use App\Http\Controllers\Property\PropertySettingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('settings')->name('settings.')->group(function () {
    Route::get('/', [PropertySettingsController::class, 'edit'])->name('edit');
    Route::get('operations', [PropertySettingsController::class, 'operations'])->name('operations');
    Route::get('registration', [PropertySettingsController::class, 'registration'])->name('registration');
    Route::get('support', [PropertySettingsController::class, 'support'])->name('support');
    Route::put('{section}', [PropertySettingsController::class, 'update'])
        ->whereIn('section', ['general', 'operations', 'registration', 'support'])
        ->name('update');
});
