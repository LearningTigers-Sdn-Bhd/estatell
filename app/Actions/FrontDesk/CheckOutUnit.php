<?php

namespace App\Actions\FrontDesk;

use App\Actions\Reservations\SyncReservationStatus;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\HousekeepingStatus;
use App\Enums\UnitStatus;
use App\Models\Bed;
use App\Models\Folio;
use App\Models\ReservationUnit;
use App\Models\RoomStatus;
use App\Models\User;
use App\Services\Folio\FolioBalance;
use App\Services\NightAudit\CurrentBusinessDate;
use Illuminate\Support\Facades\DB;

class CheckOutUnit
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private FolioBalance $folioBalance,
        private SyncReservationStatus $syncReservationStatus,
    ) {}

    /**
     * Check one unit out: set `checked_out_at` and `UnitStatus::CheckedOut`, mark its room Dirty and sync the
     * reservation status. The unit's own folio must have a balance of 0. A balance that belongs on the Master folio
     * is moved first with `TransferLines`, and a held security deposit is a credit that must be refunded or used
     * against charges first. A stay is checked out on or after its departure date. A shorter stay is changed first
     * with `ChangeUnitDates`. Permissions are checked by the route. See docs/18-front-desk/01-check-in-and-check-out.md.
     */
    public function __invoke(User $actor, ReservationUnit $unit): UnitStayResult
    {
        return DB::transaction(function () use ($actor, $unit): UnitStayResult {
            $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);

            if (! $locked->status->canTransitionTo(UnitStatus::CheckedOut)) {
                return UnitStayResult::refused(UnitStayResult::REFUSAL_INVALID_STATUS);
            }

            $businessDate = $this->currentBusinessDate->date($locked->property);

            if ($businessDate === null) {
                return UnitStayResult::refused(UnitStayResult::REFUSAL_NO_BUSINESS_DATE);
            }

            $today = $businessDate->toDateString();

            if ($today < $locked->departure_date->toDateString()) {
                return UnitStayResult::refused(UnitStayResult::REFUSAL_BEFORE_DEPARTURE_DATE);
            }

            $unsettled = Folio::query()
                ->where('reservation_unit_id', $locked->id)
                ->where('type', FolioType::Unit)
                ->where('status', '!=', FolioStatus::Voided)
                ->get()
                ->contains(fn (Folio $folio): bool => bccomp($this->folioBalance->forFolio($folio), '0', 2) !== 0);

            if ($unsettled) {
                return UnitStayResult::refused(UnitStayResult::REFUSAL_BALANCE_NOT_SETTLED);
            }

            $locked->status = UnitStatus::CheckedOut;
            $locked->checked_out_at = now();
            $locked->save();

            $roomId = $this->roomOf($locked, $today);

            if ($roomId !== null) {
                RoomStatus::query()->where('room_id', $roomId)->update([
                    'status' => HousekeepingStatus::Dirty,
                    'last_changed_by' => $actor->id,
                    'last_changed_at' => now(),
                ]);
            }

            activity()
                ->performedOn($locked->reservation)
                ->causedBy($actor)
                ->event('reservation.unit_checked_out')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'reservation_unit_id' => $locked->id,
                    'room_id' => $roomId,
                    'business_date' => $today,
                ])
                ->log('Unit checked out');

            ($this->syncReservationStatus)($locked->reservation);

            return UnitStayResult::done($locked);
        });
    }

    /**
     * The room of the unit's last night up to the business date. A bed gives its room.
     */
    private function roomOf(ReservationUnit $unit, string $today): ?int
    {
        $night = $unit->nights()->whereDate('date', '<=', $today)->where(fn ($query) => $query->whereNotNull('room_id')->orWhereNotNull('bed_id'))->orderByDesc('date')->first();

        if ($night === null) {
            return null;
        }

        return $night->room_id ?? Bed::query()->whereKey($night->bed_id)->value('room_id');
    }
}
