<?php

namespace App\Actions\Pos;

use App\Enums\PosPinOutcome;
use App\Models\PosStaffPin;
use App\Models\PosTerminalDevice;
use App\Models\Property;
use App\Models\User;
use App\Services\Pos\PosPinHasher;
use App\Services\Pos\PosPinPolicy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Check one staff user's POS PIN on a registered terminal device. Used for the quick user switch and for Manager PIN
 * overrides, so both share the same brute-force limits:
 *
 * - per (property, user, device): 5 failures in 5 minutes lock that pair for 15 minutes, so one tablet never locks
 *   another tablet behind the same hotel IP, and clearing browser cookies cannot reset a server-registered device;
 * - per (property, user): 12 failures in 15 minutes lock the user on every device for 30 minutes and log `pos.pin_locked`.
 *
 * A user who now holds an elevated POS permission with a PIN shorter than 6 digits is refused (`pin_upgrade_required`),
 * whichever way the permission was granted. Guessed PINs are never logged. See docs/34-native-pos/01-overview.md.
 */
class VerifyPosPin
{
    public function __construct(private PosPinHasher $hasher, private PosPinPolicy $policy) {}

    public function __invoke(Property $property, PosTerminalDevice $device, User $target, string $pin): PosPinResult
    {
        if ($device->property_id !== $property->id || $device->revoked_at !== null) {
            return PosPinResult::refused(PosPinOutcome::InvalidPin);
        }

        $deviceKey = "pos:pin:device:{$property->id}:{$target->id}:{$device->id}";
        $userKey = "pos:pin:user:{$property->id}:{$target->id}";

        if (Cache::has("{$deviceKey}:lock") || Cache::has("{$userKey}:lock")) {
            return PosPinResult::refused(PosPinOutcome::Locked);
        }

        if ($target->property_id !== $property->id || $target->deactivated_at !== null) {
            return PosPinResult::refused(PosPinOutcome::InvalidPin);
        }

        $staffPin = PosStaffPin::query()->where('property_id', $property->id)->where('user_id', $target->id)->where('active', true)->first();

        if ($staffPin === null) {
            return PosPinResult::refused(PosPinOutcome::NoPin);
        }

        if (! $this->hasher->check($pin, $staffPin->pin_hash)) {
            return PosPinResult::refused($this->recordFailure($property, $device, $target, $deviceKey, $userKey));
        }

        if ($staffPin->pin_length < $this->policy->minimumLength($target)) {
            return PosPinResult::refused(PosPinOutcome::PinUpgradeRequired);
        }

        RateLimiter::clear($deviceKey);
        $device->forceFill(['last_seen_at' => now()])->save();

        return PosPinResult::verified($target);
    }

    private function recordFailure(Property $property, PosTerminalDevice $device, User $target, string $deviceKey, string $userKey): PosPinOutcome
    {
        $limits = config('pos.throttle');

        RateLimiter::hit($deviceKey, $limits['device_window_minutes'] * 60);
        RateLimiter::hit($userKey, $limits['user_window_minutes'] * 60);

        $locked = false;

        if (RateLimiter::attempts($deviceKey) >= $limits['device_attempts']) {
            Cache::put("{$deviceKey}:lock", true, now()->addMinutes($limits['device_lock_minutes']));
            RateLimiter::clear($deviceKey);
            $locked = true;
            $this->logLock($property, $target, 'device', $device);
        }

        if (RateLimiter::attempts($userKey) >= $limits['user_attempts']) {
            Cache::put("{$userKey}:lock", true, now()->addMinutes($limits['user_lock_minutes']));
            RateLimiter::clear($userKey);
            $locked = true;
            $this->logLock($property, $target, 'user', $device);
        }

        return $locked ? PosPinOutcome::Locked : PosPinOutcome::InvalidPin;
    }

    private function logLock(Property $property, User $target, string $scope, PosTerminalDevice $device): void
    {
        activity()
            ->performedOn($property)
            ->event('pos.pin_locked')
            ->withProperties(['user_id' => $target->id, 'scope' => $scope, 'device_id' => $device->id])
            ->log('POS PIN locked');
    }
}
