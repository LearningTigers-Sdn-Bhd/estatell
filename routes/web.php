<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Guests\MykadReaderDownloadController;
use App\Http\Controllers\HomeRedirectController;
use App\Http\Controllers\Landing\SearchEngineController;
use App\Http\Controllers\Landing\ServicePageController;
use App\Http\Controllers\Platform\CommandSearchController;
use App\Http\Controllers\Platform\ImpersonationController;
use App\Http\Controllers\Platform\ViewAsController;
use App\Http\Controllers\Property\PropertyLogoController;
use App\Http\Middleware\EnsurePropertyAccess;
use App\Services\Landing\ServicePages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

Route::inertia('/', 'landing')->name('home');
Route::inertia('terms-and-conditions', 'legal/terms')->name('terms');
Route::inertia('privacy-policy', 'legal/privacy')->name('privacy');

// One public page per service. The slugs come from resources/js/components/landing/services.json, the same file the pages render.
Route::get('services/{slug}', [ServicePageController::class, 'show'])->whereIn('slug', ServicePages::slugs())->name('services.show');
Route::get('robots.txt', [SearchEngineController::class, 'robots'])->name('robots');
Route::get('sitemap.xml', [SearchEngineController::class, 'sitemap'])->name('sitemap');
Route::get('llms.txt', [SearchEngineController::class, 'llms'])->name('llms');
Route::get('downloads/mykad-reader/{platform}', MykadReaderDownloadController::class)->whereIn('platform', ['windows', 'mac'])->name('mykad-reader.download');

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
    Route::get('command-search', CommandSearchController::class)->middleware(['verified', 'throttle:120,1'])->name('command-search');
    Route::get('view-as/{property:code}', [ViewAsController::class, 'show'])->where(['property' => '[A-Za-z]{2}[0-9]{4,}'])->name('view-as.show');
    Route::post('view-as/{property:code}', [ViewAsController::class, 'store'])->where(['property' => '[A-Za-z]{2}[0-9]{4,}'])->middleware('support.2fa')->name('view-as.store');
    Route::post('impersonation/stop', [ImpersonationController::class, 'stop'])->name('impersonation.stop');
});

Route::prefix('p/{property:code}')->where(['property' => '[A-Za-z]{2}[0-9]{4,}'])->middleware([EnsurePropertyAccess::class, 'verified'])->group(function () {
    Route::inertia('suspended', 'property/suspended')->name('property.suspended');

    Route::middleware(['onboarding.only', 'onboarding.activity'])->group(function () {
        require __DIR__.'/onboarding.php';
    });

    Route::middleware('live')->group(function () {
        Route::get('dashboard', [DashboardController::class, 'show'])->name('dashboard');
        Route::get('dashboard/reservations/{reservation}', [DashboardController::class, 'reservation'])->scopeBindings()->middleware('can:reservations.view')->name('dashboard.reservation');
        Route::get('logo', [PropertyLogoController::class, 'show'])->name('property.logo');

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
require __DIR__.'/portal.php';
require __DIR__.'/guest.php';
