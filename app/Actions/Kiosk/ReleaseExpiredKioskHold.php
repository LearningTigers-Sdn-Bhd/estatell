<?php

namespace App\Actions\Kiosk;

use App\Actions\FrontDesk\ReleaseUnitRooms;
use App\Actions\Reservations\SyncReservationStatus;
use App\Enums\UnitStatus;
use App\Events\ReservationChanged;
use App\Models\Folio;
use App\Models\KioskWalkInHold;
use App\Models\ReservationUnit;
use App\Services\Kiosk\KioskWalkInHolds;
use Illuminate\Support\Facades\DB;

class ReleaseExpiredKioskHold
{
    public function __construct(private KioskWalkInHolds $holds, private ReleaseUnitRooms $releaseRooms, private SyncReservationStatus $syncStatus) {}

    public function __invoke(KioskWalkInHold $hold): bool
    {
        return DB::transaction(function () use ($hold): bool {
            $stay = ReservationUnit::query()->where('property_id', $hold->property_id)->lockForUpdate()->findOrFail($hold->reservation_unit_id);
            $hold = KioskWalkInHold::query()->where('property_id', $hold->property_id)->lockForUpdate()->findOrFail($hold->id);
            if ($hold->released_at !== null || $hold->expires_at->isFuture() || $stay->status !== UnitStatus::Confirmed) {
                return false;
            }
            Folio::query()->where('property_id', $hold->property_id)->where('reservation_unit_id', $stay->id)->orderBy('id')->lockForUpdate()->get();
            if ($this->holds->hasPayment($stay)) {
                return false;
            }

            ($this->releaseRooms)($stay);
            $stay->forceFill(['status' => UnitStatus::Cancelled])->save();
            $hold->forceFill(['released_at' => now()])->save();
            ($this->syncStatus)($stay->reservation);
            event(ReservationChanged::forUnit($stay));
            activity()->performedOn($stay)->event('kiosk.hold_expired')
                ->withProperties(['property_id' => $hold->property_id, 'kiosk_id' => $hold->api_token_id])->log('Unpaid kiosk hold released');

            return true;
        });
    }
}
