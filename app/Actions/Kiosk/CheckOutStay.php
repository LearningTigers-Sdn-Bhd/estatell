<?php

namespace App\Actions\Kiosk;

use App\Actions\DoorLocks\RemoveDoorCard;
use App\Actions\FrontDesk\CheckOutUnit;
use App\Actions\FrontDesk\UnitStayRefused;
use App\Actions\FrontDesk\UnitStayResult;
use App\Enums\UnitStatus;
use App\Features\DoorLocks;
use App\Models\Kiosk;
use App\Models\ReservationUnit;
use App\Services\Kiosk\KioskCheckOut;
use App\Services\Kiosk\KioskStays;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

class CheckOutStay
{
    public function __construct(
        private CheckOutUnit $checkOutUnit,
        private KioskCheckOut $checkOut,
        private KioskStays $stays,
        private RemoveDoorCard $removeCard,
    ) {}

    public function __invoke(Kiosk $kiosk, ReservationUnit $stay, ?string $uid): UnitStayResult
    {
        return Cache::lock('kiosk-register-card:'.$stay->id, 120)->get(fn () => $this->checkOut($kiosk, $stay, $uid))
            ?: UnitStayResult::refused('lock_unavailable');
    }

    private function checkOut(Kiosk $kiosk, ReservationUnit $stay, ?string $uid): UnitStayResult
    {
        try {
            $result = DB::transaction(function () use ($kiosk, $stay, $uid): UnitStayResult {
                $stay = ReservationUnit::query()->where('property_id', $kiosk->property_id)->lockForUpdate()->findOrFail($stay->id);

                if (! $this->stays->checkOutWindow($stay)) {
                    return UnitStayResult::refused('booking_not_found');
                }

                if ($uid !== null && ! $this->checkOut->ownsCard($stay, $uid)) {
                    return UnitStayResult::refused('card_unknown');
                }

                if ($stay->status === UnitStatus::CheckedOut) {
                    return $this->stays->checkedOutByKiosk($stay, $kiosk) ? UnitStayResult::done($stay) : UnitStayResult::refused('booking_not_found');
                }

                if ($stay->status !== UnitStatus::CheckedIn) {
                    return UnitStayResult::refused('booking_not_found');
                }

                $result = ($this->checkOutUnit)(null, $stay);

                if ($result->isRefused()) {
                    throw new UnitStayRefused($result);
                }

                activity()->performedOn($stay)->event('kiosk.checked_out')
                    ->withProperties(['property_id' => $stay->property_id, 'kiosk_id' => $kiosk->id])->log('Checked out at the kiosk');

                return $result;
            });
        } catch (UnitStayRefused $refused) {
            return $refused->result;
        }

        if ($result->isRefused()) {
            return $result;
        }

        // Commit each removal separately: a retry must keep remote deletions that already succeeded.
        foreach ($this->checkOut->cards($stay) as $card) {
            if ($card->ttlock_card_id !== null && (! Feature::for($kiosk->property)->active(DoorLocks::class)
                || ! $card->doorLock->has_gateway || $card->doorLock->property->doorLockConnection?->enabled !== true)) {
                return UnitStayResult::refused('lock_unavailable');
            }

            $removed = ($this->removeCard)($card);

            if ($removed->isRefused() || $removed->warning !== null) {
                return UnitStayResult::refused('lock_unavailable');
            }
        }

        return $result;
    }
}
