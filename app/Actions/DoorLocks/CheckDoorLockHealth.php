<?php

namespace App\Actions\DoorLocks;

use App\Models\DoorLock;
use App\Services\DoorLocks\TtlockApi;
use App\Services\DoorLocks\TtlockException;

/**
 * Read the health of one lock from TTLock and save it on the lock: battery, the gateway it talks through and whether that
 * gateway is online, and how far the lock clock is from ours. The battery needs no gateway. The gateway and the clock do.
 * A step that fails keeps what the earlier steps saved and leaves its own field empty, and the result carries the message, so
 * a gateway that is offline still tells staff that rather than nothing. A lock without a gateway only gets its battery.
 */
class CheckDoorLockHealth
{
    public function __construct(private TtlockApi $api, private FreshAccessToken $freshAccessToken) {}

    public function __invoke(DoorLock $lock): DoorLockResult
    {
        $connection = $lock->property->doorLockConnection;

        if ($connection === null) {
            return DoorLockResult::refused(DoorLockResult::REFUSAL_NOT_CONNECTED);
        }

        $warning = null;

        try {
            $token = ($this->freshAccessToken)($connection);

            $listed = collect($this->api->locks($token))->firstWhere('id', $lock->ttlock_lock_id);

            if ($listed !== null) {
                $lock->forceFill(['battery_percent' => $listed['battery'], 'has_gateway' => $listed['has_gateway']]);
            }

            $lock->forceFill(['gateway_name' => null, 'gateway_online' => null, 'clock_drift_seconds' => null]);

            if ($lock->has_gateway) {
                $this->gateway($lock, $token);
                $warning = $this->clock($lock, $token);
            }
        } catch (TtlockException $exception) {
            return DoorLockResult::refused(DoorLockResult::REFUSAL_TTLOCK, $exception->getMessage());
        } finally {
            $lock->forceFill(['health_checked_at' => now()])->save();
        }

        return DoorLockResult::done(warning: $warning);
    }

    private function gateway(DoorLock $lock, string $token): void
    {
        foreach ($this->api->gateways($token) as $gateway) {
            if (in_array($lock->ttlock_lock_id, $this->api->gatewayLockIds($token, $gateway['id']), true)) {
                $lock->forceFill(['gateway_name' => $gateway['name'], 'gateway_online' => $gateway['online']]);

                return;
            }
        }
    }

    /**
     * @return string|null a warning when the gateway could not reach the lock
     */
    private function clock(DoorLock $lock, string $token): ?string
    {
        try {
            $lock->clock_drift_seconds = (int) round(($this->api->lockTime($token, $lock->ttlock_lock_id)->getTimestampMs() - now()->getTimestampMs()) / 1000);
        } catch (TtlockException $exception) {
            return $exception->getMessage();
        }

        return null;
    }
}
