<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\Reports\ArrivalsReportController;
use Illuminate\Support\Facades\Route;

Route::prefix('reports')->name('reports.')->middleware('can:'.PermissionEnum::ReportsView->value)->group(function () {
    Route::get('arrivals', ArrivalsReportController::class)->name('arrivals');
});
