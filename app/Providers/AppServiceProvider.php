<?php

namespace App\Providers;

use App\Actions\Channels\RecordChannelAriChange;
use App\Actions\Channels\ScheduleChannelStructureSync;
use App\Actions\DoorLocks\QueueStayPasscodeSync;
use App\Actions\Notifications\RecordMailEvent;
use App\Actions\Platform\RecordImpersonationEnd;
use App\Enums\MailStatus;
use App\Enums\PartnerStatus;
use App\Events\ChannelStructureChanged;
use App\Events\InventoryChanged;
use App\Events\RatesChanged;
use App\Events\ReservationChanged;
use App\Events\StayCheckedIn;
use App\Events\StayCheckedOut;
use App\Events\StayRoomChanged;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Notifications\MillionSendTransport;
use App\Services\Platform\Impersonation;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\Response;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use InvalidArgumentException;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Actions\LogActivityAction;
use Spatie\Activitylog\Contracts\Activity;
use Spatie\Activitylog\Models\Activity as ActivityModel;
use Symfony\Component\Mime\Email;

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
        $this->configureMailLog();
        $this->configureChannelEvents();
    }

    /**
     * Rates, rooms and bookings tell the channels module what changed through events. One Action turns each event into
     * lines of the ARI outbox. See docs/21-channels/04-ari-sync.md.
     */
    protected function configureChannelEvents(): void
    {
        Event::listen([RatesChanged::class, InventoryChanged::class, ReservationChanged::class], fn (RatesChanged|InventoryChanged|ReservationChanged $event) => app(RecordChannelAriChange::class)($event));
        Event::listen(ChannelStructureChanged::class, fn (ChannelStructureChanged $event) => app(ScheduleChannelStructureSync::class)($event));
        Event::listen([StayCheckedIn::class, StayCheckedOut::class, StayRoomChanged::class], fn (StayCheckedIn|StayCheckedOut|StayRoomChanged $event) => app(QueueStayPasscodeSync::class)($event));

        RatePlan::saved(function (RatePlan $ratePlan): void {
            $restored = $ratePlan->wasChanged('archived_at') && $ratePlan->archived_at === null;

            if ($ratePlan->wasRecentlyCreated || $restored || $ratePlan->wasChanged(['name', 'pricing_basis', 'extra_adult_amount', 'extra_child_amount', 'parent_rate_plan_id', 'fixed_nights', 'package_unit'])) {
                event(new ChannelStructureChanged($ratePlan->property_id));
            }
        });

        RoomType::saved(function (RoomType $roomType): void {
            $restored = $roomType->wasChanged('archived_at') && $roomType->archived_at === null;

            if ($roomType->wasRecentlyCreated || $restored || $roomType->wasChanged(['name', 'description', 'max_adults', 'max_children', 'base_occupancy', 'sell_unit'])) {
                event(new ChannelStructureChanged($roomType->property_id));
            }
        });
    }

    /**
     * Every message the mailer sends leaves a mail log row. The notification class travels in the mail data, so it is
     * copied into a header before the transport runs. See docs/04-notifications/03-mail-log.md.
     */
    protected function configureMailLog(): void
    {
        Event::listen(MessageSending::class, function (MessageSending $event): void {
            if (is_string($notification = $event->data['__laravel_notification'] ?? null)) {
                $event->message->getHeaders()->addTextHeader(MillionSendTransport::NOTIFICATION_HEADER, $notification);
            }
        });

        Event::listen(MessageSent::class, function (MessageSent $event): void {
            $original = $event->sent->getOriginalMessage();

            if (! $original instanceof Email) {
                return;
            }

            $header = fn (string $name): ?string => $original->getHeaders()->get($name)?->getBodyAsString();

            app(RecordMailEvent::class)(
                $original,
                MailStatus::tryFrom((string) $header(MillionSendTransport::STATUS_HEADER)) ?? MailStatus::Sent,
                $header(MillionSendTransport::NOTIFICATION_HEADER),
                $header(MillionSendTransport::PROVIDER_ID_HEADER),
            );
        });
    }

    protected function configureMailBranding(): void
    {
        Mail::extend('millionsend', function (): MillionSendTransport {
            $baseUrl = config('services.millionsend.base_url');
            $apiKey = config('services.millionsend.api_key');

            if (blank($baseUrl) || blank($apiKey)) {
                throw new InvalidArgumentException('Set MILLIONSEND_BASE_URL and MILLIONSEND_API_KEY to use the millionsend mailer.');
            }

            return new MillionSendTransport($baseUrl, $apiKey);
        });

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
