<?php

namespace App\Actions\FrontDesk;

use App\Actions\Folio\ReopenFolio;
use App\Actions\Reservations\SyncReservationStatus;
use App\Enums\UnitStatus;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\FrontDesk\UndoCheckOutRule;
use App\Services\NightAudit\CurrentBusinessDate;
use Illuminate\Support\Facades\DB;
use Throwable;

class UndoCheckOut
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private UndoCheckOutRule $undoCheckOutRule,
        private ReopenFolio $reopenFolio,
        private SyncReservationStatus $syncReservationStatus,
    ) {}

    /**
     * Put a CheckedOut unit back to CheckedIn and clear `checked_out_at`, with a reason, and reopen the Unit folios
     * that check-out closed (`ReopenFolio`: the invoice number stays on the folio). The unit row is locked first, and
     * every check runs before the first write, so a refusal writes and logs nothing: the unit is CheckedOut
     * (`invalid_status`), a business date exists (`no_business_date`), a reason is given (`reason_required`),
     * `UndoCheckOutRule` (`undo_too_late` after the night audit, `room_taken` when another unit holds the room
     * tonight, `folio_has_ar_invoice` when a folio to reopen was settled to the city ledger).
     *
     * It keeps every payment and refund made at check-out, and after an early check-out the removed nights and the
     * early departure fee stay as they are: extend the stay and reverse the fee from the folio if they no longer
     * apply. The room stays Dirty. Permissions (`front-desk.undo-check-out`) are checked by the route.
     * See docs/18-front-desk/01-check-in-and-check-out.md#cancel-void-and-undo.
     */
    public function __invoke(User $actor, ReservationUnit $unit, string $reason): UnitStayResult
    {
        DB::beginTransaction();

        try {
            $result = $this->run($actor, $unit, $reason);
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        $result->isRefused() ? DB::rollBack() : DB::commit();

        return $result;
    }

    private function run(User $actor, ReservationUnit $unit, string $reason): UnitStayResult
    {
        $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);

        if (! $locked->status->canUndoCheckOut()) {
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

        $refusal = $this->undoCheckOutRule->refusal($locked, $businessDate);

        if ($refusal !== null) {
            return UnitStayResult::refused($refusal);
        }

        $checkedOutAt = $locked->checked_out_at;
        $folios = $this->undoCheckOutRule->foliosToReopen($locked);

        $locked->status = UnitStatus::CheckedIn;
        $locked->checked_out_at = null;
        $locked->save();

        foreach ($folios as $folio) {
            $reopened = ($this->reopenFolio)($actor, $folio);

            if ($reopened->isRefused()) {
                return UnitStayResult::refused((string) $reopened->refusal);
            }
        }

        activity()
            ->performedOn($locked->reservation)
            ->causedBy($actor)
            ->event('reservation.unit_check_out_undone')
            ->withProperties([
                'property_id' => $locked->property_id,
                'reservation_unit_id' => $locked->id,
                'reason' => $reason,
                'checked_out_at' => $checkedOutAt->toIso8601String(),
                'reopened_folio_ids' => $folios->modelKeys(),
                'business_date' => $businessDate->date->toDateString(),
            ])
            ->log('Check-out undone');

        ($this->syncReservationStatus)($locked->reservation);

        return UnitStayResult::done($locked);
    }
}
