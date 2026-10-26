<?php

namespace App\Actions\FrontDesk;

use App\Actions\Reservations\SyncReservationStatus;
use App\Enums\UnitStatus;
use App\Events\StayCheckedOut;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\FrontDesk\UndoCheckInRule;
use App\Services\NightAudit\CurrentBusinessDate;
use Illuminate\Support\Facades\DB;

class UndoCheckIn
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private UndoCheckInRule $undoCheckInRule,
        private SyncReservationStatus $syncReservationStatus,
    ) {}

    /**
     * Put a CheckedIn unit back to Confirmed and clear `checked_in_at`, with a reason. The unit row is locked first,
     * and every check runs before the first write, so a refusal writes and logs nothing. The checks, in order: the
     * unit is CheckedIn (`invalid_status`), a business date exists (`no_business_date`), a reason is given
     * (`reason_required`), and `UndoCheckInRule` (`undo_too_late` once the night audit has run since the check-in,
     * `night_posted` when a night or day use block is posted).
     *
     * It keeps what check-in posted: payments, collected taxes and the deposit, and add-ons posted at check-in stay
     * on the folio, and a correction is `ReverseLine`. The room or bed assignment stays, and cancelling the unit
     * releases it. Signed registration cards stay. Permissions (`front-desk.undo-check-in`) are checked by the route.
     * See docs/18-front-desk/01-check-in-and-check-out.md#cancel-void-and-undo.
     */
    public function __invoke(User $actor, ReservationUnit $unit, string $reason): UnitStayResult
    {
        return DB::transaction(function () use ($actor, $unit, $reason): UnitStayResult {
            $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);

            if (! $locked->status->canUndoCheckIn()) {
                return UnitStayResult::refused(UnitStayResult::REFUSAL_INVALID_STATUS);
            }

            $businessDate = $this->currentBusinessDate->get($locked->property);

            if ($businessDate === null) {
                return UnitStayResult::refused(UnitStayResult::REFUSAL_NO_BUSINESS_DATE);
            }

            $reason = trim($reason);

            if ($reason === '') {
                return UnitStayResult::refused(UnitStayResult::REFUSAL_REASON_REQUIRED);
            }

            $refusal = $this->undoCheckInRule->refusal($locked, $businessDate);

            if ($refusal !== null) {
                return UnitStayResult::refused($refusal);
            }

            $checkedInAt = $locked->checked_in_at;

            $locked->status = UnitStatus::Confirmed;
            $locked->checked_in_at = null;
            $locked->save();

            activity()
                ->performedOn($locked->reservation)
                ->causedBy($actor)
                ->event('reservation.unit_check_in_undone')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'reservation_unit_id' => $locked->id,
                    'reason' => $reason,
                    'checked_in_at' => $checkedInAt?->toIso8601String(),
                    'business_date' => $businessDate->date->toDateString(),
                ])
                ->log('Check-in undone');

            ($this->syncReservationStatus)($locked->reservation);

            StayCheckedOut::dispatch($locked->property_id, $locked->id);

            return UnitStayResult::done($locked);
        });
    }
}
