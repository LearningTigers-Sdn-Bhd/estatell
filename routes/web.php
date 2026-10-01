<?php

use App\Http\Controllers\HomeRedirectController;
use App\Http\Middleware\EnsurePropertyAccess;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::get('dashboard', HomeRedirectController::class)->middleware('auth')->name('home.redirect');

Route::prefix('p/{property:code}')->whereNumber('property')->middleware([EnsurePropertyAccess::class, 'verified'])->group(function () {
    Route::inertia('suspended', 'property/suspended')->name('property.suspended');
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    require __DIR__.'/onboarding.php';
    require __DIR__.'/front-desk.php';
    require __DIR__.'/guests.php';
    require __DIR__.'/night-audit.php';
    require __DIR__.'/rates.php';
    require __DIR__.'/settings.php';
});

require __DIR__.'/account.php';
require __DIR__.'/staff.php';
require __DIR__.'/admin.php';
require __DIR__.'/guest.php';
