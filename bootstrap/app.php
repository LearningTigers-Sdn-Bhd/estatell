<?php

use App\Http\Middleware\EndExpiredImpersonationSession;
use App\Http\Middleware\EnsureBoatsIsActive;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsurePropertyAccess;
use App\Http\Middleware\EnsurePropertyIsLive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LogoutDeactivatedUsers;
use App\Http\Middleware\RequireSupportTwoFactor;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->redirectUsersTo(fn (Request $request): string => $request->user()?->is_admin ? route('admin.dashboard') : route('home.redirect'));

        $middleware->alias([
            'support.2fa' => RequireSupportTwoFactor::class,
            'boats' => EnsureBoatsIsActive::class,
            'live' => EnsurePropertyIsLive::class,
            'property.access' => EnsurePropertyAccess::class,
            'verified' => EnsureEmailIsVerified::class,
        ]);

        $middleware->appendToPriorityList(SubstituteBindings::class, EnsurePropertyAccess::class);
        $middleware->appendToPriorityList(EnsurePropertyAccess::class, EnsurePropertyIsLive::class);
        $middleware->appendToPriorityList(EnsurePropertyAccess::class, EnsureBoatsIsActive::class);

        $middleware->web(append: [
            AuthenticateSession::class,
            LogoutDeactivatedUsers::class,
            EnsurePasswordChanged::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            EndExpiredImpersonationSession::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
