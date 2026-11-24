<?php

use App\Http\Controllers\Group\GroupDashboardController;
use App\Http\Controllers\Group\GroupForecastController;
use App\Http\Controllers\Group\GroupPropertyReportController;
use App\Http\Controllers\Group\GroupReportsController;
use App\Http\Controllers\Group\GroupReservationsController;
use App\Http\Controllers\Group\GroupTimelineController;
use Illuminate\Support\Facades\Route;

/*
 * Group mode: the properties of one group seen together by a staff user with access to two or more of them. Every
 * figure follows the user's role at each property. See docs/03-staff/05-group-mode.md.
 */
Route::get('dashboard', GroupDashboardController::class)->name('dashboard');
Route::get('timeline', GroupTimelineController::class)->name('timeline');
Route::get('reports', [GroupReportsController::class, 'show'])->name('reports');
Route::get('reports/export.csv', [GroupReportsController::class, 'export'])->name('reports.export');
Route::get('reports/export.pdf', [GroupReportsController::class, 'pdf'])->name('reports.pdf');
Route::get('forecast', [GroupForecastController::class, 'show'])->name('forecast');
Route::get('forecast/export.csv', [GroupForecastController::class, 'export'])->name('forecast.export');
Route::get('forecast/export.pdf', [GroupForecastController::class, 'pdf'])->name('forecast.pdf');
Route::get('property-reports', [GroupPropertyReportController::class, 'show'])->name('property-reports');
Route::get('property-reports/export.csv', [GroupPropertyReportController::class, 'export'])->name('property-reports.export');
Route::get('property-reports/export.pdf', [GroupPropertyReportController::class, 'pdf'])->name('property-reports.pdf');
Route::get('reservations', [GroupReservationsController::class, 'index'])->name('reservations');
Route::get('reservations/export.csv', [GroupReservationsController::class, 'export'])->name('reservations.export');
Route::get('reservations/export.pdf', [GroupReservationsController::class, 'pdf'])->name('reservations.pdf');
