<?php

namespace App\Providers;

use App\Models\Property;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\Response;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureTrustedProxies();
        $this->configureGates();
        $this->configureRouteBindings();
    }

    /**
     * Bind `{property}` by its public code on every route, including `Route::inertia` routes that
     * have no typed controller parameter for implicit binding.
     */
    protected function configureRouteBindings(): void
    {
        Route::bind('property', fn (string $code): Property => Property::query()->where('code', $code)->firstOrFail());
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(8)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Define the platform admin gate. Admins are users with is_admin set. Others get a 404, so /admin
     * stays hidden (see docs/01-platform/01-platform-admins.md).
     */
    protected function configureGates(): void
    {
        Gate::define('admin', fn (User $user): Response => $user->is_admin ? Response::allow() : Response::denyAsNotFound());
    }

    /**
     * Trust the proxies listed in TRUSTED_PROXIES, expanding "CLOUDFLARE" to its published ranges.
     */
    protected function configureTrustedProxies(): void
    {
        $proxies = array_map(trim(...), explode(',', (string) config('trustedproxy.proxies')));

        if ($proxies === ['*']) {
            TrustProxies::at('*');

            return;
        }

        TrustProxies::at(collect($proxies)
            ->flatMap(fn (string $proxy): array => $proxy === 'CLOUDFLARE' ? config('trustedproxy.cloudflare') : [$proxy])
            ->filter()
            ->values()
            ->all());
    }
}
