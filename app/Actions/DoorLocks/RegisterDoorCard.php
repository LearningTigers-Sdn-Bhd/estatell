<?php

namespace App\Actions\DoorLocks;

use App\Enums\PasscodeStatus;
use App\Models\DoorCard;
use App\Models\DoorLock;
use App\Models\DoorLockConnection;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\DoorLocks\CardNumber;
use App\Services\DoorLocks\TtlockApi;
use App\Services\DoorLocks\TtlockException;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

/**
 * Register a guest's IC card with one lock, by its UID (hex). Nothing is written to the card. It needs a gateway: without
 * one the action refuses and writes nothing. Safe to repeat with the same `$idempotencyKey`: a Pending or Active row is
 * returned as it is, a Failed row is tried again. A card live on the same lock for another key is refused. An expected TTLock
 * failure is saved on the row as Failed with the message. With `$patient` (a person is waiting at a screen that can wait) a failure is
 * tried again after a pause, up to three tries; the kiosk is not patient, because its guest waits only 10 seconds.
 */
class RegisterDoorCard
{
    private const LIVE = [PasscodeStatus::Pending, PasscodeStatus::Active];

    /** Tries in all. A gateway that was idle answers "busy" to the first call and takes the next one. */
    private const ATTEMPTS = 3;

    private const PAUSE_SECONDS = 4;

    /** TTLock answers that waiting does not change: the lock memory is full, or the sign-in has expired. */
    private const NOT_WORTH_RETRYING = [-3009, 10004];

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
        bool $patient = false,
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

        $card = DoorCard::query()->where('property_id', $lock->property_id)->where('idempotency_key', $idempotencyKey)->first();

        if ($card !== null && in_array($card->status, self::LIVE, true)) {
            return DoorLockResult::done(card: $card);
        }

        $taken = DoorCard::query()->where('door_lock_id', $lock->id)->where('card_number', $number)->whereIn('status', self::LIVE)->exists();

        if ($taken) {
            return DoorLockResult::refused(DoorLockResult::REFUSAL_CARD_IN_USE);
        }

        $card ??= new DoorCard;

        try {
            // The transaction is a savepoint when a caller already has one, so a lost race does not poison it.
            DB::transaction(fn () => $card->forceFill([
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
            ])->save());
        } catch (UniqueConstraintViolationException) {
            return $this->lostRace($lock, $idempotencyKey);
        }

        try {
            $card->forceFill([
                'ttlock_card_id' => $this->addWithRetry($connection, $lock, $number, $label, $startsAt, $endsAt, $patient ? self::ATTEMPTS : 1),
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

    /**
     * `addCard`, tried again after a pause when TTLock fails. ponytail: any failure but the ones listed is retried, as TTLock's
     * code for a busy gateway is not known; the cost is two more waits for a refusal that will not pass.
     */
    private function addWithRetry(DoorLockConnection $connection, DoorLock $lock, int $number, string $label, CarbonImmutable $startsAt, CarbonImmutable $endsAt, int $attempts): int
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->api->addCard(($this->freshAccessToken)($connection), $lock->ttlock_lock_id, $number, $label, $startsAt, $endsAt);
            } catch (TtlockException $exception) {
                if ($attempt >= $attempts || in_array($exception->ttlockCode, self::NOT_WORTH_RETRYING, true)) {
                    throw $exception;
                }

                Sleep::for(self::PAUSE_SECONDS)->seconds();
            }
        }
    }

    /**
     * Another request saved first: the database refused the second row. The same key live is that request's card, so a
     * repeat gets it. Otherwise the card went to another key and is in use.
     */
    private function lostRace(DoorLock $lock, string $idempotencyKey): DoorLockResult
    {
        $winner = DoorCard::query()
            ->where('property_id', $lock->property_id)
            ->where('idempotency_key', $idempotencyKey)
            ->whereIn('status', self::LIVE)
            ->first();

        return $winner !== null ? DoorLockResult::done(card: $winner) : DoorLockResult::refused(DoorLockResult::REFUSAL_CARD_IN_USE);
    }
}
