<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeRedirectController;
use App\Http\Controllers\Platform\CommandSearchController;
use App\Http\Controllers\Platform\ImpersonationController;
use App\Http\Controllers\Platform\ViewAsController;
use App\Http\Middleware\EnsurePropertyAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

Route::inertia('/', 'welcome')->name('home');

Route::get('_preview/errors/{status}', function (Request $request, int $status): Response {
    abort_unless(app()->environment('local'), 404);

    Inertia::flushShared();

    return Inertia::render('errors', [
        'status' => $status,
        'recoveryUrl' => $status === 503 ? $request->fullUrl() : route('home.redirect', absolute: false),
    ])->toResponse($request)->setStatusCode($status);
})->whereIn('status', ['403', '404', '419', '500', '503'])->name('errors.preview');

Route::get('dashboard', HomeRedirectController::class)->middleware('auth')->name('home.redirect');

Route::middleware('auth')->group(function () {
    Route::get('view-as/{property:code}', [ViewAsController::class, 'show'])->where(['property' => '[A-Za-z]{2}[0-9]{5,}'])->name('view-as.show');
    Route::get('command-search', CommandSearchController::class)->middleware(['verified', 'throttle:120,1'])->name('command-search');
    Route::post('view-as/{property:code}', [ViewAsController::class, 'store'])->where(['property' => '[A-Za-z]{2}[0-9]{5,}'])->middleware('support.2fa')->name('view-as.store');
    Route::post('impersonation/stop', [ImpersonationController::class, 'stop'])->name('impersonation.stop');
});

Route::prefix('p/{property:code}')->where(['property' => '[A-Za-z]{2}[0-9]{5,}'])->middleware([EnsurePropertyAccess::class, 'verified'])->group(function () {
    Route::inertia('suspended', 'property/suspended')->name('property.suspended');

    Route::middleware('onboarding.only')->group(function () {
        require __DIR__.'/onboarding.php';
    });

    Route::middleware('live')->group(function () {
        Route::get('dashboard', [DashboardController::class, 'show'])->name('dashboard');

        require __DIR__.'/night-audit.php';
        require __DIR__.'/rates.php';
        require __DIR__.'/settings.php';
        require __DIR__.'/finance.php';
        require __DIR__.'/front-desk.php';
        require __DIR__.'/guests.php';
        require __DIR__.'/housekeeping.php';
        require __DIR__.'/registration.php';
        require __DIR__.'/reports.php';
        require __DIR__.'/reservations.php';
    });
});

require __DIR__.'/account.php';
require __DIR__.'/staff.php';
require __DIR__.'/admin.php';
require __DIR__.'/partner.php';
require __DIR__.'/guest.php';
