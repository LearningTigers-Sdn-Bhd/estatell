<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\Rates\RateCalendarController;
use Illuminate\Support\Facades\Route;

Route::prefix('rates')->name('rates.')->group(function () {
    Route::get('calendar', [RateCalendarController::class, 'index'])->middleware('can:'.PermissionEnum::RatesView->value)->name('calendar');
    Route::get('calendar/bulk', [RateCalendarController::class, 'bulk'])->middleware('can:'.PermissionEnum::RatesManage->value)->name('calendar.bulk');
    Route::put('calendar/bulk/{ratePlan}', [RateCalendarController::class, 'bulkUpdate'])->middleware('can:'.PermissionEnum::RatesManage->value)->scopeBindings()->name('calendar.bulk.update');
    Route::put('calendar/{ratePlan}', [RateCalendarController::class, 'update'])->middleware('can:'.PermissionEnum::RatesManage->value)->scopeBindings()->name('calendar.update');
    Route::post('calendar/extend/{ratePlan}', [RateCalendarController::class, 'extend'])->middleware('can:'.PermissionEnum::RatesManage->value)->scopeBindings()->name('calendar.extend');
});
