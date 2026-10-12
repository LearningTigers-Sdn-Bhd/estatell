<?php

namespace App\Services\FrontDesk;

use App\Actions\Folio\FolioStatusResult;
use App\Actions\FrontDesk\UnitStayResult;
use App\Enums\ArInvoiceStatus;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\StayType;
use App\Models\ArInvoice;
use App\Models\Bed;
use App\Models\BusinessDate;
use App\Models\Folio;
use App\Models\ReservationUnit;
use App\Models\UnitDayUse;
use App\Models\UnitNight;
use Illuminate\Support\Collection;

/**
 * When a check-out can be undone: the unit is CheckedOut, it checked out during the open business date
 * (`checked_out_at` is at or after `business_dates.opened_at`, so the night audit has not run since) and its room or
 * bed is not held by another unit tonight and none of its folios was settled to the city ledger. The unit menu, the preview and `UndoCheckOut` all ask this one class. It
 * reads only. See docs/18-front-desk/01-check-in-and-check-out.md#cancel-void-and-undo.
 */
class UndoCheckOutRule
{
    public function refusal(ReservationUnit $unit, BusinessDate $businessDate): ?string
    {
        if (! $unit->status->canUndoCheckOut()) {
            return UnitStayResult::REFUSAL_INVALID_STATUS;
        }

        if ($unit->checked_out_at === null || $unit->checked_out_at->lt($businessDate->opened_at)) {
            return UnitStayResult::REFUSAL_UNDO_TOO_LATE;
        }

        if ($this->isTaken($unit, $businessDate->date->toDateString())) {
            return UnitStayResult::REFUSAL_ROOM_TAKEN;
        }

        if ($this->isSettledToCityLedger($unit)) {
            return FolioStatusResult::REFUSAL_HAS_AR_INVOICE;
        }

        return null;
    }

    public function isOffered(ReservationUnit $unit, BusinessDate $businessDate): bool
    {
        return $this->refusal($unit, $businessDate) === null;
    }

    /**
     * The unit folios that check-out closed (closed at or after `checked_out_at`).
     *
     * @return Collection<int, Folio>
     */
    public function foliosToReopen(ReservationUnit $unit): Collection
    {
        return Folio::query()
            ->where('reservation_unit_id', $unit->id)
            ->where('type', FolioType::Unit)
            ->where('status', FolioStatus::Closed)
            ->where('closed_at', '>=', $unit->checked_out_at)
            ->orderBy('id')
            ->get();
    }

    /**
     * One of those folios has an AR invoice that is not Void, so `ReopenFolio` would refuse it.
     */
    private function isSettledToCityLedger(ReservationUnit $unit): bool
    {
        return ArInvoice::query()
            ->whereIn('folio_id', $this->foliosToReopen($unit)->modelKeys())
            ->where('status', '<>', ArInvoiceStatus::Void)
            ->exists();
    }

    /**
     * Another unit holds the room (or the bed) the guest used last: a night on the business date, or a day use block
     * on it that is not released.
     */
    private function isTaken(ReservationUnit $unit, string $today): bool
    {
        if ($unit->stay_type === StayType::DayUse) {
            $block = $unit->dayUse;

            return $block?->room_id !== null && UnitDayUse::query()
                ->where('room_id', $block->room_id)
                ->whereDate('date', $block->date->toDateString())
                ->whereNull('released_at')
                ->where('reservation_unit_id', '!=', $unit->id)
                ->exists();
        }

        $last = $unit->nights()->whereDate('date', '<=', $today)->where(fn ($query) => $query->whereNotNull('room_id')->orWhereNotNull('bed_id'))->orderByDesc('date')->first();

        if ($last === null) {
            return false;
        }

        $held = UnitNight::query()->whereDate('date', $today)->where('reservation_unit_id', '!=', $unit->id);

        if ($last->bed_id !== null) {
            $roomId = Bed::query()->whereKey($last->bed_id)->value('room_id');
            $held->where(fn ($query) => $query->where('bed_id', $last->bed_id)->orWhere('room_id', $roomId));

            return $held->exists();
        }

        return $held->where('room_id', $last->room_id)->exists();
    }
}
