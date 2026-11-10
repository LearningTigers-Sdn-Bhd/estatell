<?php

namespace App\Actions\Kiosk;

use App\Actions\DoorLocks\RegisterDoorCard;
use App\Enums\LockProvider;
use App\Enums\PasscodeKind;
use App\Enums\PasscodeStatus;
use App\Enums\UnitStatus;
use App\Features\DoorLocks;
use App\Models\DoorCard;
use App\Models\ReservationUnit;
use App\Services\DoorLocks\CardNumber;
use App\Services\DoorLocks\PasscodeWindow;
use App\Services\DoorLocks\StayLocks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class RegisterStayCard
{
    /** Cards one stay can hold on one lock, so a token cannot fill a lock with cards. */
    private const int MAX_CARDS_PER_LOCK = 4;

    public function __construct(
        private StayLocks $stayLocks,
        private RegisterDoorCard $registerDoorCard,
        private CardNumber $cardNumber,
        private PasscodeWindow $passcodeWindow,
    ) {}

    /**
     * Register the physical card the kiosk dispensed with the lock of every room of the stay, from now to the
     * departure. Nothing is written to the card. `RegisterDoorCard` does the TTLock call and is safe to repeat: the
     * idempotency key is per stay, lock and card number, so a retry gets the card it already made, and a failed
     * attempt is tried again. The answer is `registered` only when TTLock has the card on every lock (status Active).
     * A card still being registered by another request, no lock, no gateway, TTLock down or refusing, a card already
     * on that lock for another stay, and too many cards all answer `lock_unavailable`, so the machine never ejects
     * a card that may not open the door. A UID that is not a valid card number answers `card_unknown`.
     */
    public function __invoke(ReservationUnit $stay, string $uid): KioskCardResult
    {
        $number = $this->cardNumber->fromUid($uid);

        if ($number === null) {
            return KioskCardResult::refused('card_unknown');
        }

        return Cache::lock('kiosk-register-card:'.$stay->id, 120)->get(fn () => $this->register($stay, $uid, $number))
            ?: KioskCardResult::refused('lock_unavailable');
    }

    private function register(ReservationUnit $stay, string $uid, int $number): KioskCardResult
    {
        $stay->refresh();

        if ($stay->status !== UnitStatus::CheckedIn || ! DoorLocks::allows($stay->property, LockProvider::TTLock) || $stay->property->doorLockConnection?->enabled !== true) {
            return KioskCardResult::refused('lock_unavailable');
        }

        $locks = $this->stayLocks->for($stay);

        if ($locks->isEmpty()) {
            return KioskCardResult::refused('lock_unavailable');
        }

        $timeZone = $stay->property->settings->time_zone ?? 'Asia/Kuala_Lumpur';
        [$startsAt, $endsAt] = $this->passcodeWindow->forUnit($stay, PasscodeKind::Custom, $timeZone);
        $label = mb_substr('Stay '.$stay->reservation_id.' card', 0, 60);
        $cards = [];

        foreach ($locks as $lock) {
            $key = "stay:{$stay->id}:{$lock->id}:card:{$number}";
            $held = DoorCard::query()->where('reservation_unit_id', $stay->id)->where('door_lock_id', $lock->id)->whereIn('status', [PasscodeStatus::Pending, PasscodeStatus::Active]);

            if ($held->count() >= self::MAX_CARDS_PER_LOCK && ! $held->clone()->where('idempotency_key', $key)->exists()) {
                return KioskCardResult::refused('lock_unavailable');
            }

            $result = ($this->registerDoorCard)($lock, $uid, $label, $startsAt, $endsAt, $key, $stay);

            if ($result->isRefused() || $result->card?->status !== PasscodeStatus::Active) {
                return KioskCardResult::refused('lock_unavailable');
            }

            $cards[] = $result->card;
        }

        return KioskCardResult::registered(CarbonImmutable::instance($cards[0]->ends_at));
    }
}
