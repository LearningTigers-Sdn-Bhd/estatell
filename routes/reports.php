<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\Reports\ArrivalsReportController;
use App\Http\Controllers\Reports\ReportExportController;
use App\Http\Controllers\Reports\ReportLayoutController;
use Illuminate\Support\Facades\Route;

Route::prefix('reports')->name('reports.')->middleware('can:'.PermissionEnum::ReportsView->value)->group(function () {
    Route::get('arrivals', ArrivalsReportController::class)->name('arrivals');

    Route::post('{report}/pdf', [ReportExportController::class, 'pdf'])->name('pdf');
    Route::post('{report}/spreadsheet', [ReportExportController::class, 'spreadsheet'])->name('spreadsheet');

    Route::prefix('{report}/layout')->name('layout.')->middleware('can:'.PermissionEnum::SettingsManage->value)->group(function () {
        Route::put('/', [ReportLayoutController::class, 'update'])->name('update');
        Route::delete('/', [ReportLayoutController::class, 'destroy'])->name('destroy');
        Route::post('preview', [ReportLayoutController::class, 'preview'])->name('preview');
    });
});
