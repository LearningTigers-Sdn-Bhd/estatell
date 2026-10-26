<?php

namespace App\Actions\DoorLocks;

use App\Enums\PasscodeKind;
use App\Enums\PasscodeStatus;
use App\Models\DoorLock;
use App\Models\DoorPasscode;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\DoorLocks\PasscodeWindow;
use App\Services\DoorLocks\TtlockApi;
use App\Services\DoorLocks\TtlockException;
use Carbon\CarbonImmutable;

/**
 * Issue one PIN on one lock. A generated PIN is made by TTLock and needs no gateway. A custom PIN (4 to 9 digits, or a
 * random 6 when `$code` is null) is sent to the lock and needs a gateway: without one the action refuses and writes nothing.
 *
 * The `$idempotencyKey` makes it safe to repeat. A row that is Pending or Active is returned as it is, a Failed row is
 * tried again. An expected TTLock failure is saved on the row as Failed with the message, so staff can press Retry.
 */
class IssueDoorPasscode
{
    public function __construct(
        private TtlockApi $api,
        private FreshAccessToken $freshAccessToken,
        private PasscodeWindow $passcodeWindow,
    ) {}

    public function __invoke(
        DoorLock $lock,
        PasscodeKind $kind,
        string $label,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        string $idempotencyKey,
        ?string $code = null,
        ?ReservationUnit $unit = null,
        ?User $actor = null,
    ): DoorLockResult {
        $connection = $lock->property->doorLockConnection;

        if ($connection === null) {
            return DoorLockResult::refused(DoorLockResult::REFUSAL_NOT_CONNECTED);
        }

        if ($kind->needsGateway() && ! $lock->has_gateway) {
            return DoorLockResult::refused(DoorLockResult::REFUSAL_GATEWAY_REQUIRED);
        }

        if ($kind === PasscodeKind::Custom) {
            $code ??= (string) random_int(100000, 999999);

            if (preg_match('/^\d{4,9}$/', $code) !== 1) {
                return DoorLockResult::refused(DoorLockResult::REFUSAL_INVALID_CODE);
            }
        }

        [$startsAt, $endsAt] = $this->passcodeWindow->fit($startsAt, $endsAt, $kind);

        if ($endsAt->lte(now())) {
            return DoorLockResult::refused(DoorLockResult::REFUSAL_WINDOW_PASSED);
        }

        $passcode = DoorPasscode::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($passcode !== null && in_array($passcode->status, [PasscodeStatus::Pending, PasscodeStatus::Active], true)) {
            return DoorLockResult::done($passcode);
        }

        $passcode ??= new DoorPasscode;
        $passcode->forceFill([
            'property_id' => $lock->property_id,
            'door_lock_id' => $lock->id,
            'reservation_unit_id' => $unit?->id,
            'kind' => $kind,
            'status' => PasscodeStatus::Pending,
            'code' => $code,
            'ttlock_passcode_id' => null,
            'label' => $label,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'error' => null,
            'revoked_at' => null,
            'revoke_note' => null,
            'issued_by' => $actor?->id,
            'idempotency_key' => $idempotencyKey,
        ])->save();

        try {
            $token = ($this->freshAccessToken)($connection);

            if ($kind === PasscodeKind::Generated) {
                $made = $this->api->generatedPasscode($token, $lock->ttlock_lock_id, $label, $startsAt, $endsAt);
                $passcode->forceFill(['code' => $made['code'], 'ttlock_passcode_id' => $made['id']]);
            } else {
                $passcode->ttlock_passcode_id = $this->api->customPasscode($token, $lock->ttlock_lock_id, (string) $code, $label, $startsAt, $endsAt);
            }

            $passcode->forceFill(['status' => PasscodeStatus::Active, 'error' => null])->save();
        } catch (TtlockException $exception) {
            $passcode->forceFill(['status' => PasscodeStatus::Failed, 'error' => $exception->getMessage()])->save();

            return DoorLockResult::refused(DoorLockResult::REFUSAL_TTLOCK, $exception->getMessage(), $passcode);
        }

        activity()
            ->performedOn($passcode)
            ->causedBy($actor)
            ->event('door-locks.passcode-issued')
            ->withProperties(['property_id' => $lock->property_id, 'door_lock_id' => $lock->id, 'reservation_unit_id' => $unit?->id, 'kind' => $kind->value])
            ->log('Door PIN issued');

        return DoorLockResult::done($passcode);
    }
}
