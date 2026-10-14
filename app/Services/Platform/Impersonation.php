<?php

namespace App\Services\Platform;

use App\Enums\PropertyStatus;
use App\Models\Property;
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
     * Whether `to` may be opened for this property: a relative path (never a full URL), a real staff route, with the
     * property's own code, that is not blocked during support access.
     */
    public function accepts(Property $property, string $to): bool
    {
        if (! str_starts_with($to, '/') || str_starts_with($to, '//') || str_contains($to, '\\')) {
            return false;
        }

        $route = $this->matchRoute($to);

        return $route !== null
            && str_starts_with($route->uri(), 'p/{property')
            && Str::upper((string) $route->parameter('property')) === $property->code
            && ! $this->isBlocked($route);
    }

    /**
     * Routes denied during support access. Nothing is blocked until the BlockDuringImpersonation commit.
     */
    private function isBlocked(Route $route): bool
    {
        return false;
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
     * @return array{support_user_id: int, user_id: int, property_id: int, reason: string, note: string|null, started_at: string, return_url: string}|null
     */
    private function session(): ?array
    {
        $session = Session::get(self::SESSION_KEY);

        return is_array($session) && isset($session['support_user_id'], $session['user_id'], $session['property_id'], $session['started_at']) ? $session : null;
    }
}
