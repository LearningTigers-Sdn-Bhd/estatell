<?php

use App\Http\Controllers\Group\GroupDashboardController;
use App\Http\Controllers\Group\GroupReportsController;
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
