<?php

use App\Http\Controllers\Group\GroupDashboardController;
use Illuminate\Support\Facades\Route;

/*
 * Group mode: the properties of one group seen together by a staff user with access to two or more of them. Every
 * figure follows the user's role at each property. See docs/03-staff/05-group-mode.md.
 */
Route::get('dashboard', GroupDashboardController::class)->name('dashboard');
