<?php

namespace App\Actions\DoorLocks;

use App\Enums\PasscodeStatus;
use App\Models\DoorCard;
use App\Models\DoorLock;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\DoorLocks\CardNumber;
use App\Services\DoorLocks\TtlockApi;
use App\Services\DoorLocks\TtlockException;
use Carbon\CarbonImmutable;

/**
 * Register a guest's IC card with one lock, by its UID (hex). Nothing is written to the card. It needs a gateway: without
 * one the action refuses and writes nothing. Safe to repeat with the same `$idempotencyKey`: a Pending or Active row is
 * returned as it is, a Failed row is tried again. A card live on the same lock for another key is refused. An expected TTLock
 * failure is saved on the row as Failed with the message.
 */
class RegisterDoorCard
{
    public function __construct(
        private TtlockApi $api,
        private FreshAccessToken $freshAccessToken,
        private CardNumber $cardNumber,
    ) {}

    public function __invoke(
        DoorLock $lock,
        string $uid,
        string $label,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        string $idempotencyKey,
        ?ReservationUnit $unit = null,
        ?User $actor = null,
    ): DoorLockResult {
        $connection = $lock->property->doorLockConnection;

        if ($connection === null) {
            return DoorLockResult::refused(DoorLockResult::REFUSAL_NOT_CONNECTED);
        }

        if (! $lock->has_gateway) {
            return DoorLockResult::refused(DoorLockResult::REFUSAL_GATEWAY_REQUIRED);
        }

        $number = $this->cardNumber->fromUid($uid);

        if ($number === null) {
            return DoorLockResult::refused(DoorLockResult::REFUSAL_INVALID_CARD);
        }

        if ($endsAt->lte(now()) || $endsAt->lte($startsAt)) {
            return DoorLockResult::refused(DoorLockResult::REFUSAL_WINDOW_PASSED);
        }

        $live = [PasscodeStatus::Pending, PasscodeStatus::Active];
        $card = DoorCard::query()->where('property_id', $lock->property_id)->where('idempotency_key', $idempotencyKey)->first();

        if ($card !== null && in_array($card->status, $live, true)) {
            return DoorLockResult::done(card: $card);
        }

        $taken = DoorCard::query()->where('door_lock_id', $lock->id)->where('card_number', $number)->whereIn('status', $live)->exists();

        if ($taken) {
            return DoorLockResult::refused(DoorLockResult::REFUSAL_CARD_IN_USE);
        }

        $card ??= new DoorCard;
        $card->forceFill([
            'property_id' => $lock->property_id,
            'door_lock_id' => $lock->id,
            'reservation_unit_id' => $unit?->id,
            'card_number' => $number,
            'ttlock_card_id' => null,
            'status' => PasscodeStatus::Pending,
            'label' => $label,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'error' => null,
            'revoked_at' => null,
            'idempotency_key' => $idempotencyKey,
        ])->save();

        try {
            $card->forceFill([
                'ttlock_card_id' => $this->api->addCard(($this->freshAccessToken)($connection), $lock->ttlock_lock_id, $number, $label, $startsAt, $endsAt),
                'status' => PasscodeStatus::Active,
            ])->save();
        } catch (TtlockException $exception) {
            $card->forceFill(['status' => PasscodeStatus::Failed, 'error' => $exception->getMessage()])->save();

            return DoorLockResult::refused(DoorLockResult::REFUSAL_TTLOCK, $exception->getMessage(), card: $card);
        }

        activity()
            ->performedOn($card)
            ->causedBy($actor)
            ->event('door-locks.card-registered')
            ->withProperties(['property_id' => $lock->property_id, 'door_lock_id' => $lock->id, 'reservation_unit_id' => $unit?->id])
            ->log('Door card registered');

        return DoorLockResult::done(card: $card);
    }
}
