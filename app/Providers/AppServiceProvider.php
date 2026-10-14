<?php

namespace App\Providers;

use App\Actions\Platform\RecordImpersonationEnd;
use App\Enums\PartnerStatus;
use App\Models\Property;
use App\Models\User;
use App\Services\Platform\Impersonation;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\Response;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Actions\LogActivityAction;
use Spatie\Activitylog\Contracts\Activity;
use Spatie\Activitylog\Models\Activity as ActivityModel;

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
        $this->configureFeatures();
        $this->configureSupportSessions();
        $this->configureMailBranding();
    }

    protected function configureMailBranding(): void
    {
        ResetPassword::toMailUsing(function (User $user, string $token): MailMessage {
            return (new MailMessage)
                ->subject(__('Reset your password'))
                ->markdown('notifications::email', ['mailTitle' => __('Reset your password.'), 'propertyName' => $user->property?->name])
                ->line(__('You are receiving this email because we received a password reset request for your account.'))
                ->action(__('Reset Password'), url(route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()], false)))
                ->line(__('This password reset link will expire in :count minutes.', ['count' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire')]))
                ->line(__('If you did not request a password reset, no further action is required.'));
        });

        VerifyEmail::toMailUsing(function (User $user, string $url): MailMessage {
            return (new MailMessage)
                ->subject(__('Verify your email address'))
                ->markdown('notifications::email', ['mailTitle' => __('Confirm it’s your email.'), 'propertyName' => $user->property?->name])
                ->line(__('Please click the button below to verify your email address.'))
                ->action(__('Verify Email Address'), $url)
                ->line(__('If you did not create an account, no further action is required.'));
        });
    }

    /**
     * Features are checked per property: the `{property}` in the URL, or the signed-in staff user's own
     * property. Admins outside a property have no scope. See docs/01-platform/03-feature-flags.md.
     */
    protected function configureFeatures(): void
    {
        Feature::discover();

        Feature::resolveScopeUsing(function (): ?Property {
            $property = request()->route('property');

            return $property instanceof Property ? $property : Auth::user()?->property;
        });
    }

    /**
     * Bind `{property}` by its public code on every route, including `Route::inertia` routes that
     * have no typed controller parameter for implicit binding. An unknown code is a 404, except on the view-as page,
     * where an admin is told the code was not found (anyone else must not be able to probe codes).
     */
    protected function configureRouteBindings(): void
    {
        Route::bind('property', function (string $code, RoutingRoute $route): Property {
            $property = Property::query()->where('code', Str::upper($code))->first();

            if ($property !== null) {
                return $property;
            }

            if ($route->named('view-as.show') && (app(Impersonation::class)->supportUser() ?? Auth::user())?->is_admin) {
                Inertia::flash('toast', ['type' => 'error', 'message' => __('Property :code not found.', ['code' => Str::upper($code)])]);

                throw new HttpResponseException(redirect()->route('admin.properties.index'));
            }

            throw (new ModelNotFoundException)->setModel(Property::class);
        });
    }

    /**
     * While a support user views a property as its staff, every activity log entry written for that staff user is
     * recorded with the support user as causer and `impersonated_user_id` in its properties. The callback runs for
     * every entry, before it is saved. See docs/01-platform/04-impersonation.md. The session ends when the user logs
     * out, so that is logged here too, while the session data is still readable.
     */
    protected function configureSupportSessions(): void
    {
        LogActivityAction::beforeLogging(function (Activity $activity): void {
            $impersonation = app(Impersonation::class);

            if (! $activity instanceof ActivityModel || ! request()->hasSession() || ! $impersonation->isActive() || $activity->causer_id !== Auth::id() || $activity->causer_type !== (new User)->getMorphClass()) {
                return;
            }

            $activity->causer()->associate($impersonation->supportUser());
            $activity->properties = $activity->properties->put('impersonated_user_id', Auth::id());
        });

        Event::listen(Logout::class, function (): void {
            if (app(Impersonation::class)->isActive()) {
                app(RecordImpersonationEnd::class)();
            }
        });
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
     * Define the platform admin and partner gates. Admins are users with is_admin set. Others get a 404, so /admin
     * stays hidden (see docs/01-platform/01-platform-admins.md). Both gates check the signed-in user only, so a support
     * session (signed in as staff) never reaches /admin or /partner.
     */
    protected function configureGates(): void
    {
        Gate::define('partner', fn (User $user): Response => $user->partner_id !== null && $user->partner?->status === PartnerStatus::Active
            ? Response::allow()
            : Response::denyAsNotFound());
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
