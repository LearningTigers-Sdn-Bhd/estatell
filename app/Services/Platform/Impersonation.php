<?php

namespace App\Services\Platform;

use App\Enums\PermissionEnum;
use App\Enums\PropertyStatus;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Reads the support session kept in `session('impersonation')` while an admin or partner user views a property as
 * one of its staff: `{support_user_id, user_id, property_id, reason, note, started_at, return_url}`. It never writes.
 * See docs/01-platform/04-impersonation.md.
 */
class Impersonation
{
    public const string SESSION_KEY = 'impersonation';

    public const int LIMIT_MINUTES = 30;

    /**
     * Route names denied during support access, for any method. Fortify's two-factor and password-confirm routes are
     * listed by name because the vendor registers them. `two-factor.login` stays open, because it is the sign-in step.
     *
     * @var list<string>
     */
    private const array BLOCKED_ROUTES = [
        'account.password.update',
        'account.security.*',
        'account.sessions.destroy',
        'account.set-password.*',
        'account.profile.destroy',
        'account.notifications.update',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.disable',
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
        'password.confirm',
        'password.confirm.store',
        'guests.documents.scan',
        'guests.documents.reveal',
        'onboarding.go-live.store',
        'onboarding.go-live.withdraw',
    ];

    /**
     * Money corrections: refunds, voids, credit notes and allocation reversals. They follow the owner's switch
     * `allow_support_money_corrections` instead of always being denied. Folio line reversals are not listed: fixing
     * charges is setup work.
     *
     * @var list<string>
     */
    private const array MONEY_CORRECTION_ROUTES = [
        'reservations.folios.payments.refunds.store',
        'reservations.folios.void-invoice-and-reopen',
        'registration.void',
        'finance.city-ledger.*.void',
        'finance.city-ledger.invoices.credit-notes.store',
        'finance.city-ledger.companies.payments.allocations.reverse',
    ];

    /**
     * Route names denied for every method except GET and HEAD, so the staff lists stay readable.
     *
     * @var list<string>
     */
    private const array BLOCKED_NON_GET_ROUTES = [
        'settings.staff.*',
    ];

    /**
     * Whether the signed-in user is a staff user being viewed by a support user.
     */
    public function isActive(): bool
    {
        $session = $this->session();

        return $session !== null && Auth::id() === $session['user_id'];
    }

    public function forProperty(Property $property): bool
    {
        return $this->isActive() && $this->session()['property_id'] === $property->id;
    }

    /**
     * The admin or partner user behind the session, or null when nobody is impersonating.
     */
    public function supportUser(): ?User
    {
        return $this->isActive() ? User::query()->find($this->session()['support_user_id']) : null;
    }

    /**
     * Minutes left of the 30-minute limit, for display.
     */
    public function remainingMinutes(): int
    {
        $startedAt = $this->startedAt();

        if ($startedAt === null) {
            return 0;
        }

        return max(0, (int) ceil(($startedAt->addMinutes(self::LIMIT_MINUTES)->getTimestamp() - now()->getTimestamp()) / 60));
    }

    /**
     * Whole minutes since the session started. A session never lasts longer than the limit, so this stops there.
     */
    public function elapsedMinutes(): int
    {
        $startedAt = $this->startedAt();

        return $startedAt === null ? 0 : min(self::LIMIT_MINUTES, max(0, (int) floor((now()->getTimestamp() - $startedAt->getTimestamp()) / 60)));
    }

    /**
     * Whether the signed-in staff user's session has run past the 30-minute limit.
     */
    public function hasExpired(): bool
    {
        $startedAt = $this->startedAt();

        return $this->isActive() && $startedAt !== null && now()->getTimestamp() >= $startedAt->addMinutes(self::LIMIT_MINUTES)->getTimestamp();
    }

    /**
     * The facts of the open session for the activity log and the owner notification: the support user, their partner,
     * the staff user, the property, the reason and the note. Empty when no session is open.
     *
     * @return array<string, int|string|null>
     */
    public function logProperties(): array
    {
        $supportUser = $this->supportUser();
        $user = Auth::user();

        if ($supportUser === null || ! $user instanceof User) {
            return [];
        }

        $partner = $supportUser->partner;

        return [
            'property_id' => $this->session()['property_id'],
            'support_user_id' => $supportUser->id,
            'support_user_name' => $supportUser->name,
            'partner_id' => $partner?->id,
            'partner_name' => $partner?->name,
            'impersonated_user_id' => $user->id,
            'impersonated_user_name' => $user->name,
            'reason' => $this->session()['reason'],
            'note' => $this->session()['note'] ?? null,
        ];
    }

    /**
     * When the session started.
     */
    public function startedAt(): ?CarbonImmutable
    {
        $startedAt = $this->session()['started_at'] ?? null;

        return $startedAt === null ? null : now()->parse($startedAt);
    }

    /**
     * The path to return to when the session ends.
     */
    public function returnUrl(): ?string
    {
        return $this->session()['return_url'] ?? null;
    }

    /**
     * The page to open once a session starts: `to` when it passes {@see accepts()}, otherwise the property's start page.
     */
    public function destination(Property $property, ?string $to): string
    {
        if ($to !== null && $this->accepts($property, $to)) {
            return $to;
        }

        return $property->status === PropertyStatus::Onboarding
            ? route('onboarding.basics.edit', ['property' => $property->code], absolute: false)
            : route('dashboard', ['property' => $property->code], absolute: false);
    }

    /**
     * Whether a route is denied during support access. `$method` matters only for the staff settings routes, where
     * GET and HEAD stay open.
     */
    public function isBlockedRoute(string $routeName, string $method = 'GET'): bool
    {
        if (Str::is(self::BLOCKED_ROUTES, $routeName)) {
            return true;
        }

        return ! in_array(Str::upper($method), ['GET', 'HEAD'], true) && Str::is(self::BLOCKED_NON_GET_ROUTES, $routeName);
    }

    /**
     * Whether a route is a money correction (refund, void, credit note or allocation reversal).
     */
    public function isMoneyCorrectionRoute(string $routeName): bool
    {
        return Str::is(self::MONEY_CORRECTION_ROUTES, $routeName);
    }

    /**
     * Whether the signed-in support user may refund, void and issue credit notes now: true outside a session, and
     * during one only when the property's owner allows money corrections.
     */
    public function canCorrectMoney(): bool
    {
        if (! $this->isActive()) {
            return true;
        }

        return $this->allowsMoneyCorrections($this->session()['property_id']);
    }

    /**
     * Whether `$user` may refund now: the permission, and during a support session the owner's money switch.
     */
    public function mayRefund(User $user): bool
    {
        return $user->can(PermissionEnum::PaymentRefund->value) && $this->canCorrectMoney();
    }

    /**
     * Whether the property's owner allows support to make money corrections during a session. Defaults to true.
     */
    public function allowsMoneyCorrections(int $propertyId): bool
    {
        return PropertySetting::query()->where('property_id', $propertyId)->first()->allow_support_money_corrections ?? true;
    }

    /**
     * Whether `to` is a relative path to a route that is blocked for this property during support access, so the
     * view-as page can warn.
     */
    public function isBlockedPath(Property $property, string $to): bool
    {
        if (! $this->isRelativePath($to)) {
            return false;
        }

        $route = $this->matchRoute($to);

        return $route !== null && $route->getName() !== null && $this->isBlockedForProperty($property, $route->getName());
    }

    /**
     * Whether `to` may be opened for this property: a relative path (never a full URL), a real staff route, with the
     * property's own code, that is not blocked during support access.
     */
    public function accepts(Property $property, string $to): bool
    {
        if (! $this->isRelativePath($to)) {
            return false;
        }

        $route = $this->matchRoute($to);

        if ($route === null || ! str_starts_with($route->uri(), 'p/{property')) {
            return false;
        }

        $propertyCode = $route->parameter('property');

        return is_string($propertyCode)
            && Str::upper($propertyCode) === $property->code
            && ($route->getName() === null || ! $this->isBlockedForProperty($property, $route->getName()));
    }

    /**
     * Blocked for this property: always blocked, or a money correction while the owner has switched them off.
     */
    private function isBlockedForProperty(Property $property, string $routeName): bool
    {
        return $this->isBlockedRoute($routeName)
            || ($this->isMoneyCorrectionRoute($routeName) && ! $this->allowsMoneyCorrections($property->id));
    }

    private function isRelativePath(string $to): bool
    {
        return str_starts_with($to, '/') && ! str_starts_with($to, '//') && ! str_contains($to, '\\');
    }

    private function matchRoute(string $path): ?Route
    {
        try {
            return RouteFacade::getRoutes()->match(Request::create($path));
        } catch (HttpException) {
            return null;
        }
    }

    /**
     * The session data when complete, or null.
     *
     * @return array{support_user_id: int, user_id: int, property_id: int, reason: string|null, note: string|null, started_at: string, return_url: string|null}|null
     */
    private function session(): ?array
    {
        $session = Session::get(self::SESSION_KEY);

        if (! is_array($session) || ! isset($session['support_user_id'], $session['user_id'], $session['property_id'], $session['started_at'])) {
            return null;
        }

        return [
            'support_user_id' => (int) $session['support_user_id'],
            'user_id' => (int) $session['user_id'],
            'property_id' => (int) $session['property_id'],
            'reason' => isset($session['reason']) ? (string) $session['reason'] : null,
            'note' => isset($session['note']) ? (string) $session['note'] : null,
            'started_at' => (string) $session['started_at'],
            'return_url' => isset($session['return_url']) ? (string) $session['return_url'] : null,
        ];
    }
}
