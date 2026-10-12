<?php

namespace App\Services\FrontDesk;

use App\Actions\FrontDesk\UnitStayResult;
use App\Enums\StayType;
use App\Models\BusinessDate;
use App\Models\FolioLine;
use App\Models\ReservationUnit;
use Illuminate\Support\Facades\DB;

/**
 * When a check-in can be undone: the unit is CheckedIn, it checked in during the open business date (`checked_in_at`
 * is at or after `business_dates.opened_at`, so the night audit has not run since) and nothing for a night or a day
 * use block is posted for it. A day use unit never qualifies, because its block is posted at check-in. The unit menu,
 * the preview and `UndoCheckIn` all ask this one class. It reads only.
 * See docs/18-front-desk/01-check-in-and-check-out.md#cancel-void-and-undo.
 */
class UndoCheckInRule
{
    public function refusal(ReservationUnit $unit, BusinessDate $businessDate): ?string
    {
        if (! $unit->status->canUndoCheckIn()) {
            return UnitStayResult::REFUSAL_INVALID_STATUS;
        }

        if ($unit->checked_in_at === null || $unit->checked_in_at->lt($businessDate->opened_at)) {
            return UnitStayResult::REFUSAL_UNDO_TOO_LATE;
        }

        if ($unit->stay_type === StayType::DayUse || $this->hasPostedNight($unit)) {
            return UnitStayResult::REFUSAL_NIGHT_POSTED;
        }

        return null;
    }

    public function isOffered(ReservationUnit $unit, BusinessDate $businessDate): bool
    {
        return $this->refusal($unit, $businessDate) === null;
    }

    /**
     * A line with the key of one of the unit's nights (`night:{id}` and its `:fix:` and `:incl:` children) or of its
     * day use block (`dayuse:{id}`).
     */
    private function hasPostedNight(ReservationUnit $unit): bool
    {
        $dayUse = 'dayuse:'.$unit->id;

        return FolioLine::query()
            ->where(fn ($lines) => $lines
                ->where('idempotency_key', $dayUse)
                ->orWhere('idempotency_key', 'like', $dayUse.':%')
                ->orWhereExists(fn ($nights) => $nights
                    ->select(DB::raw(1))
                    ->from('unit_nights')
                    ->where('unit_nights.reservation_unit_id', $unit->id)
                    ->whereRaw("(folio_lines.idempotency_key = 'night:' || unit_nights.id or folio_lines.idempotency_key like 'night:' || unit_nights.id || ':%')")))
            ->exists();
    }
}
