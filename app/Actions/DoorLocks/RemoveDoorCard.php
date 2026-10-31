<?php

namespace App\Actions\DoorLocks;

use App\Enums\PasscodeStatus;
use App\Models\DoorCard;
use App\Models\User;
use App\Services\DoorLocks\TtlockApi;
use App\Services\DoorLocks\TtlockException;

/**
 * Take a card away from its lock. With a gateway the card is deleted at TTLock. Without one it cannot be reached: the row is
 * marked Revoked and the result carries a warning that the lock still accepts the card until it ends. A card that never
 * reached the lock is just marked Revoked. A Revoked or Expired card is refused. A TTLock failure keeps the row as it was.
 */
class RemoveDoorCard
{
    public function __construct(private TtlockApi $api, private FreshAccessToken $freshAccessToken) {}

    public function __invoke(DoorCard $card, ?User $actor = null): DoorLockResult
    {
        if (! $card->status->canTransitionTo(PasscodeStatus::Revoked)) {
            return DoorLockResult::refused(DoorLockResult::REFUSAL_UNCHANGEABLE, card: $card);
        }

        $lock = $card->doorLock;
        $warning = null;

        if ($card->ttlock_card_id !== null) {
            $connection = $lock->property->doorLockConnection;

            if ($lock->has_gateway && $connection !== null) {
                try {
                    $this->api->deleteCard(($this->freshAccessToken)($connection), $lock->ttlock_lock_id, $card->ttlock_card_id);
                } catch (TtlockException $exception) {
                    $card->forceFill(['error' => $exception->getMessage()])->save();

                    return DoorLockResult::refused(DoorLockResult::REFUSAL_TTLOCK, $exception->getMessage(), card: $card);
                }
            } elseif ($card->ends_at->isFuture()) {
                $warning = __('The lock has no gateway, so it still accepts this card until :time. Delete it in the TTLock app to stop it sooner.', ['time' => $card->ends_at->toDateTimeString()]);
            }
        }

        $card->forceFill(['status' => PasscodeStatus::Revoked, 'revoked_at' => now(), 'error' => null])->save();

        activity()
            ->performedOn($card)
            ->causedBy($actor)
            ->event('door-locks.card-removed')
            ->withProperties(['property_id' => $card->property_id, 'door_lock_id' => $card->door_lock_id, 'warned' => $warning !== null])
            ->log('Door card removed');

        return DoorLockResult::done(warning: $warning, card: $card);
    }
}
