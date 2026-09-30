<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\Rates\RateCalendarController;
use Illuminate\Support\Facades\Route;

Route::prefix('rates')->name('rates.')->group(function () {
    Route::get('calendar', [RateCalendarController::class, 'index'])->middleware('can:'.PermissionEnum::RatesView->value)->name('calendar');
    Route::post('calendar/extend/{ratePlan}', [RateCalendarController::class, 'extend'])->middleware('can:'.PermissionEnum::RatesManage->value)->scopeBindings()->name('calendar.extend');
});
