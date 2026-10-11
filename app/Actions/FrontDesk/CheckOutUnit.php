<?php

namespace App\Actions\FrontDesk;

use App\Actions\Folio\CloseFolio;
use App\Actions\Folio\FolioStatusResult;
use App\Actions\Reservations\SyncReservationStatus;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\HousekeepingStatus;
use App\Enums\StayType;
use App\Enums\UnitStatus;
use App\Models\Bed;
use App\Models\Folio;
use App\Models\ReservationUnit;
use App\Models\RoomStatus;
use App\Models\User;
use App\Services\Folio\FolioBalance;
use App\Services\FrontDesk\CheckOutSettlement;
use App\Services\NightAudit\CurrentBusinessDate;
use Illuminate\Support\Facades\DB;

class CheckOutUnit
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private FolioBalance $folioBalance,
        private SyncReservationStatus $syncReservationStatus,
        private CloseFolio $closeFolio,
        private CheckOutSettlement $checkOutSettlement,
    ) {}

    /**
     * Check one unit out: set `checked_out_at` and `UnitStatus::CheckedOut`, mark its room Dirty and sync the
     * reservation status. No other live unit may still charge unposted Scheduled rows to the unit's own folios
     * (`folio_receives_scheduled_charges`, checked first so the message is the right one). The unit's own folio must
     * have a balance of 0, whoever the charges belong to; `balance_not_settled` names the rooms whose charges it holds. A balance that belongs on the Master folio
     * is moved first with `TransferLines`, and a held security deposit is a credit that must be refunded or used
     * against charges first. After the status change it closes each Open Unit folio of the unit through `CloseFolio`
     * (at 0 by the check above), which issues the folio's invoice number once. A close that is refused (a posting raced
     * in) rolls the check-out back as `balance_not_settled`. Master and ThirdParty folios stay Open. No `folio.close`
     * is needed: closing is part of check-out. A stay is checked out on or after its departure date. A shorter stay is changed first
     * with `ChangeUnitDates`. Permissions are checked by the route. See docs/18-front-desk/01-check-in-and-check-out.md.
     */
    public function __invoke(User $actor, ReservationUnit $unit): UnitStayResult
    {
        try {
            return $this->checkOut($actor, $unit);
        } catch (UnitStayRefused $refused) {
            return $refused->result;
        }
    }

    private function checkOut(User $actor, ReservationUnit $unit): UnitStayResult
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

            $folios = $this->checkOutSettlement->folios($locked);
            $incoming = $this->checkOutSettlement->incomingScheduled($locked, $folios);

            if ($incoming !== []) {
                return UnitStayResult::refused(UnitStayResult::REFUSAL_RECEIVES_SCHEDULED_CHARGES, $incoming);
            }

            $unsettled = $folios->contains(fn (Folio $folio): bool => bccomp($this->folioBalance->forFolio($folio), '0', 2) !== 0);

            if ($unsettled) {
                return UnitStayResult::refused(UnitStayResult::REFUSAL_BALANCE_NOT_SETTLED, $this->checkOutSettlement->heldForOtherRooms($locked, $folios));
            }

            $locked->status = UnitStatus::CheckedOut;
            $locked->checked_out_at = now();
            $locked->save();

            $openFolios = Folio::query()
                ->where('reservation_unit_id', $locked->id)
                ->where('type', FolioType::Unit)
                ->where('status', FolioStatus::Open)
                ->orderBy('id')
                ->get();

            foreach ($openFolios as $folio) {
                $closed = ($this->closeFolio)($actor, $folio);

                if ($closed->refusal === FolioStatusResult::REFUSAL_RECEIVES_SCHEDULED_CHARGES) {
                    throw new UnitStayRefused(UnitStayResult::refused(UnitStayResult::REFUSAL_RECEIVES_SCHEDULED_CHARGES, $closed->rooms));
                }

                if ($closed->isRefused()) {
                    throw new UnitStayRefused(UnitStayResult::refused(UnitStayResult::REFUSAL_BALANCE_NOT_SETTLED));
                }
            }

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
     * The room of the unit's last night up to the business date, or of its day use block. A bed gives its room.
     */
    private function roomOf(ReservationUnit $unit, string $today): ?int
    {
        if ($unit->stay_type === StayType::DayUse) {
            return $unit->dayUse()->value('room_id');
        }

        $night = $unit->nights()->whereDate('date', '<=', $today)->where(fn ($query) => $query->whereNotNull('room_id')->orWhereNotNull('bed_id'))->orderByDesc('date')->first();

        if ($night === null) {
            return null;
        }

        return $night->room_id ?? Bed::query()->whereKey($night->bed_id)->value('room_id');
    }
}
