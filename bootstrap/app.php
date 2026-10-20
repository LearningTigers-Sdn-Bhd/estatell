<?php

use App\Http\Middleware\BlockDuringImpersonation;
use App\Http\Middleware\BlockOnDemo;
use App\Http\Middleware\EndExpiredImpersonationSession;
use App\Http\Middleware\EnsureBoatsIsActive;
use App\Http\Middleware\EnsureChannelManagerIsActive;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\EnsureOnboarding;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsurePortalAccess;
use App\Http\Middleware\EnsurePropertyAccess;
use App\Http\Middleware\EnsurePropertyIsLive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LogoutDeactivatedUsers;
use App\Http\Middleware\RequireSupportTwoFactor;
use App\Http\Middleware\TrackOnboardingActivity;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\View;
use Inertia\ExceptionResponse;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

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
            'block.impersonation' => BlockDuringImpersonation::class,
            'support.2fa' => RequireSupportTwoFactor::class,
            'boats' => EnsureBoatsIsActive::class,
            'channels' => EnsureChannelManagerIsActive::class,
            'live' => EnsurePropertyIsLive::class,
            'onboarding.activity' => TrackOnboardingActivity::class,
            'onboarding.only' => EnsureOnboarding::class,
            'portal' => EnsurePortalAccess::class,
            'property.access' => EnsurePropertyAccess::class,
            'verified' => EnsureEmailIsVerified::class,
        ]);

        $middleware->appendToPriorityList(SubstituteBindings::class, EnsurePropertyAccess::class);
        $middleware->appendToPriorityList(SubstituteBindings::class, EnsurePortalAccess::class);
        $middleware->appendToPriorityList(EnsurePropertyAccess::class, EnsurePropertyIsLive::class);
        $middleware->appendToPriorityList(EnsurePropertyAccess::class, EnsureOnboarding::class);
        $middleware->appendToPriorityList(EnsureOnboarding::class, TrackOnboardingActivity::class);
        $middleware->appendToPriorityList(EnsurePropertyAccess::class, EnsureBoatsIsActive::class);
        $middleware->appendToPriorityList(EnsurePropertyAccess::class, EnsureChannelManagerIsActive::class);

        $middleware->web(append: [
            AuthenticateSession::class,
            LogoutDeactivatedUsers::class,
            EnsurePasswordChanged::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            EndExpiredImpersonationSession::class,
            BlockDuringImpersonation::class,
            BlockOnDemo::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        Inertia::handleExceptionsUsing(function (ExceptionResponse $exception): ?Response {
            $request = $exception->request;
            $status = $exception->statusCode();

            if ($request->is('api/*') || $request->expectsJson()
                || ! in_array($status, [403, 404, 419, 500, 503], true)
                || ($status === 500 && app()->environment('local'))) {
                return null;
            }

            $recoveryUrl = route('home.redirect', absolute: false);
            $referrer = $request->headers->get('referer');
            $parts = $referrer === null ? false : parse_url($referrer);

            if (is_array($parts) && isset($parts['scheme'], $parts['host'])
                && strtolower($parts['scheme']) === $request->getScheme()
                && strtolower($parts['host']) === $request->getHost()
                && ($parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80)) === $request->getPort()
                && ! str_contains($referrer, '\\')
                && preg_match('/[\x00-\x20\x7f]/', $referrer) === 0) {
                $path = $parts['path'] ?? '/';

                if (str_starts_with($path, '/') && ! str_starts_with($path, '//')) {
                    $recoveryUrl = $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
                }
            }

            if ($status === 503 && $request->isMethod('GET')) {
                $recoveryUrl = $request->fullUrl();
            }

            $appearance = $request->cookie('appearance');
            View::share('appearance', in_array($appearance, ['light', 'dark', 'system'], true) ? $appearance : 'system');
            Inertia::flushShared();

            $response = $exception->rootView('app')->render('errors', [
                'status' => $status,
                'recoveryUrl' => $recoveryUrl,
            ])->toResponse($request);

            $headers = $exception->response->headers->all();
            unset($headers['content-type'], $headers['content-length']);
            $response->headers->add($headers);
            $response->setVary('X-Inertia', replace: false);

            return $response;
        });
    })->create();
