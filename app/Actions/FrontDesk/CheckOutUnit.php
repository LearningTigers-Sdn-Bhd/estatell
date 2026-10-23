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
use App\Services\FrontDesk\StayDay;
use App\Services\NightAudit\CurrentBusinessDate;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CheckOutUnit
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private FolioBalance $folioBalance,
        private SyncReservationStatus $syncReservationStatus,
        private CloseFolio $closeFolio,
        private CheckOutSettlement $checkOutSettlement,
        private StayDay $stayDay,
        private PostPendingNight $postPendingNight,
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
     * is needed: closing is part of check-out. A stay is checked out on or after its departure date, counted on the stay
     * day (`StayDay`): the calendar date while the night audit is pending. Then `PostPendingNight` posts the night of the
     * business date first, so the balance check sees it, and the log carries the stay day as `business_date`, or the business date when the unit departs on it (a guest who left yesterday). A past stay
     * (`$asOf`, `$skipBalanceCheck`) keeps the business date and posts no pending night. A shorter stay is changed first
     * with `ChangeUnitDates`. `$asOf` is the moment the guest really left, for a past stay entered after it ended
     * (`CreatePastStay`); it sets `checked_out_at`. `$skipBalanceCheck` (only `CreatePastStay` passes it) leaves the
     * balance, the scheduled-charge check and the folios alone: the folios stay Open with their balance, and the
     * room is not marked Dirty, because the stay ended in the past. `$sameDayLeaver` (only the wastays replay passes it)
     * checks out a one-night stay on its arrival date, before its departure date: its night posts first with the audit's
     * key, so the folio closes and the invoice is issued on that business date. Permissions are checked by the route. See docs/18-front-desk/01-check-in-and-check-out.md.
     */
    public function __invoke(User $actor, ReservationUnit $unit, ?CarbonImmutable $asOf = null, bool $skipBalanceCheck = false, bool $sameDayLeaver = false): UnitStayResult
    {
        try {
            return $this->checkOut($actor, $unit, $asOf, $skipBalanceCheck, $sameDayLeaver);
        } catch (UnitStayRefused $refused) {
            return $refused->result;
        }
    }

    private function checkOut(User $actor, ReservationUnit $unit, ?CarbonImmutable $asOf, bool $skipBalanceCheck, bool $sameDayLeaver): UnitStayResult
    {
        return DB::transaction(function () use ($actor, $unit, $asOf, $skipBalanceCheck, $sameDayLeaver): UnitStayResult {
            $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);

            if (! $locked->status->canTransitionTo(UnitStatus::CheckedOut)) {
                return UnitStayResult::refused(UnitStayResult::REFUSAL_INVALID_STATUS);
            }

            $businessDate = $this->currentBusinessDate->date($locked->property);

            if ($businessDate === null) {
                return UnitStayResult::refused(UnitStayResult::REFUSAL_NO_BUSINESS_DATE);
            }

            $today = $businessDate->toDateString();
            $stayDay = $skipBalanceCheck || $asOf !== null || $locked->stay_type === StayType::DayUse ? $today : $this->stayDay->forCheckOut($locked->property, $businessDate)->toDateString();

            $leavesOnArrival = $sameDayLeaver && $locked->arrival_date->toDateString() === $today && $locked->departure_date->toDateString() === $businessDate->addDay()->toDateString();

            if ($stayDay < $locked->departure_date->toDateString() && ! $leavesOnArrival) {
                return UnitStayResult::refused(UnitStayResult::REFUSAL_BEFORE_DEPARTURE_DATE);
            }

            if (! $skipBalanceCheck && $asOf === null) {
                ($this->postPendingNight)($actor, $locked, $leavesOnArrival);
            }

            if (! $skipBalanceCheck) {
                $folios = $this->checkOutSettlement->folios($locked);
                $incoming = $this->checkOutSettlement->incomingScheduled($locked, $folios);

                if ($incoming !== []) {
                    return UnitStayResult::refused(UnitStayResult::REFUSAL_RECEIVES_SCHEDULED_CHARGES, $incoming);
                }

                $unsettled = $folios->contains(fn (Folio $folio): bool => ! BigDecimal::of($this->folioBalance->forFolio($folio))->isZero());

                if ($unsettled) {
                    return UnitStayResult::refused(UnitStayResult::REFUSAL_BALANCE_NOT_SETTLED, $this->checkOutSettlement->heldForOtherRooms($locked, $folios));
                }
            }

            $locked->status = UnitStatus::CheckedOut;
            $locked->checked_out_at = $asOf ?? now();
            $locked->save();

            $openFolios = $skipBalanceCheck ? [] : $this->openUnitFolios($locked);

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

            if (! $skipBalanceCheck) {
                $this->markRoomDirty($actor, $roomId);
            }

            activity()
                ->performedOn($locked->reservation)
                ->causedBy($actor)
                ->event('reservation.unit_checked_out')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'reservation_unit_id' => $locked->id,
                    'room_id' => $roomId,
                    'business_date' => $locked->departure_date->toDateString() === $today ? $today : $stayDay,
                ])
                ->log('Unit checked out');

            ($this->syncReservationStatus)($locked->reservation);

            return UnitStayResult::done($locked);
        });
    }

    /**
     * @return Collection<int, Folio>
     */
    private function openUnitFolios(ReservationUnit $unit): Collection
    {
        return Folio::query()
            ->where('reservation_unit_id', $unit->id)
            ->where('type', FolioType::Unit)
            ->where('status', FolioStatus::Open)
            ->orderBy('id')
            ->get();
    }

    private function markRoomDirty(User $actor, ?int $roomId): void
    {
        if ($roomId === null) {
            return;
        }

        RoomStatus::query()->where('room_id', $roomId)->update([
            'status' => HousekeepingStatus::Dirty,
            'last_changed_by' => $actor->id,
            'last_changed_at' => now(),
        ]);
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
