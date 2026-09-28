<?php

use App\Http\Controllers\Onboarding\PropertyBasicsController;
use Illuminate\Support\Facades\Route;

Route::prefix('onboarding')->name('onboarding.')->group(function () {
    Route::get('basics', [PropertyBasicsController::class, 'edit'])->name('basics.edit');
    Route::put('basics', [PropertyBasicsController::class, 'update'])->name('basics.update');
});
