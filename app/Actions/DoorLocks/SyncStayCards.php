<?php

namespace App\Actions\DoorLocks;

use App\Enums\PasscodeKind;
use App\Enums\PasscodeStatus;
use App\Enums\UnitStatus;
use App\Models\DoorCard;
use App\Models\ReservationUnit;
use App\Services\DoorLocks\PasscodeWindow;
use App\Services\DoorLocks\StayLocks;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Make a unit's guest cards match its stay, after any change to it. A unit that is no longer checked in loses its cards on the
 * lock, like its PIN. A checked-in unit keeps each live card on every lock of its rooms (a room move adds the new lock) and until
 * its departure (a date change removes the card and registers it again with the new end, which TTLock cannot edit). A card staff
 * removed from one lock is not put back. The kiosk cleans up its own check-out and card registration, so a unit it holds is skipped.
 */
class SyncStayCards
{
    public function __construct(
        private RegisterDoorCard $registerDoorCard,
        private RemoveDoorCard $removeDoorCard,
        private PasscodeWindow $passcodeWindow,
        private StayLocks $stayLocks,
    ) {}

    public function __invoke(ReservationUnit $unit): void
    {
        // Not tied to "Issue PINs automatically": a card exists because staff or the kiosk added it, and must still end with the stay.
        if ($unit->property->doorLockConnection === null) {
            return;
        }

        $kioskLock = Cache::lock('kiosk-register-card:'.$unit->id, 120);

        if (! $kioskLock->get()) {
            return;
        }

        try {
            $cards = DoorCard::query()->where('reservation_unit_id', $unit->id)->with('doorLock.property.doorLockConnection')->get();

            if ($unit->status !== UnitStatus::CheckedIn) {
                $cards->whereIn('status', [PasscodeStatus::Pending, PasscodeStatus::Active])->each(fn (DoorCard $card) => ($this->removeDoorCard)($card));

                return;
            }

            $this->match($unit, $cards);
        } finally {
            $kioskLock->release();
        }
    }

    /**
     * @param  Collection<int, DoorCard>  $cards
     */
    private function match(ReservationUnit $unit, Collection $cards): void
    {
        $locks = $this->stayLocks->for($unit);
        [, $endsAt] = $this->passcodeWindow->forUnit($unit, PasscodeKind::Custom, $unit->property->settings->time_zone ?? 'Asia/Kuala_Lumpur');
        $live = [PasscodeStatus::Pending, PasscodeStatus::Active];
        $label = mb_substr('Stay '.$unit->reservation_id.' card', 0, 60);

        foreach ($cards->whereIn('status', $live)->pluck('card_number')->unique() as $number) {
            $uid = strtoupper(str_pad(dechex($number), $number > 0xFFFFFFFF ? 14 : 8, '0', STR_PAD_LEFT));
            $own = $cards->where('card_number', $number);

            foreach ($locks as $lock) {
                $row = $own->where('door_lock_id', $lock->id)->whereIn('status', $live)->first();
                $key = "stay:{$unit->id}:{$lock->id}:card:{$number}";

                if ($row === null && $own->where('door_lock_id', $lock->id)->isNotEmpty()) {
                    continue;
                }

                if ($row !== null) {
                    if (abs($row->ends_at->diffInSeconds($endsAt, false)) <= 60) {
                        continue;
                    }

                    ($this->removeDoorCard)($row);
                    $key .= ':'.$endsAt->getTimestamp();
                }

                ($this->registerDoorCard)($lock, $uid, $label, now()->toImmutable(), $endsAt, $key, $unit);
            }
        }
    }
}
