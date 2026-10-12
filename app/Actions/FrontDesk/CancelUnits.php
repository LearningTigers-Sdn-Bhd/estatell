<?php

namespace App\Actions\FrontDesk;

use App\Actions\Reservations\SyncReservationStatus;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Throwable;

class CancelUnits
{
    public function __construct(
        private CancelUnit $cancelUnit,
        private SyncReservationStatus $syncReservationStatus,
    ) {}

    /**
     * Cancel the chosen units of a reservation in one transaction, all or nothing: one `CancelUnit` call each, in
     * unit id order, and the first refusal rolls every unit back and names its room. A unit id that is not a unit of
     * the reservation is ignored, and no unit left is refused (`no_units_selected`). `$expectedFees` maps a unit id to
     * the fee the form showed (a decimal string, before tax); `CancelUnit` refuses `fee_changed` when it differs.
     * `$doNotCharge` and `$reason` apply to every unit, and the route passes Do not charge only with
     * `front-desk.waive-fees`. Each unit keeps its own `reservation.unit_cancelled` entry, and one
     * `reservation.cancelled` entry holds the count, the total fee, the reason and whether a company portal
     * cancellation request was resolved (`cancellation_requested_at` is cleared). Permissions are checked by the
     * route. See docs/18-front-desk/01-check-in-and-check-out.md#cancel-reservation.
     *
     * @param  list<int>  $unitIds
     * @param  array<int, string|null>  $expectedFees
     */
    public function __invoke(User $actor, Reservation $reservation, array $unitIds, bool $doNotCharge = false, ?string $reason = null, bool $removeAddOns = false, array $expectedFees = []): CancelUnitsResult
    {
        $units = $reservation->units()->whereIn('id', $unitIds)->with(['roomType', 'nights.room', 'nights.bed'])->orderBy('id')->get();

        if ($units->isEmpty()) {
            return CancelUnitsResult::refused(CancelUnitsResult::REFUSAL_NO_UNITS);
        }

        $reason = filled($reason) ? trim((string) $reason) : null;

        DB::beginTransaction();

        try {
            $total = BigDecimal::zero();

            foreach ($units as $unit) {
                /** @var ReservationUnit $unit */
                $result = ($this->cancelUnit)($actor, $unit, $doNotCharge, $reason, $expectedFees[$unit->id] ?? null, $removeAddOns);

                if ($result->isRefused()) {
                    DB::rollBack();

                    return CancelUnitsResult::refused((string) $result->refusal, $unit->id, $this->roomLabel($unit));
                }

                $total = $total->plus((string) $result->fee);
            }

            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $resolved = $locked->cancellation_requested_at !== null;
            $requestedBy = $locked->cancellation_requested_by;

            if ($resolved) {
                $locked->cancellation_requested_at = null;
                $locked->cancellation_requested_by = null;
                $locked->save();
            }

            ($this->syncReservationStatus)($locked);

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('reservation.cancelled')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'reservation_unit_ids' => $units->modelKeys(),
                    'count' => $units->count(),
                    'total_fee' => (string) $total->toScale(2),
                    'did_not_charge' => $doNotCharge,
                    'reason' => $reason,
                    'resolved_cancellation_request' => $resolved,
                    'cancellation_requested_by' => $requestedBy,
                ])
                ->log($resolved ? 'Reservation cancelled, company request resolved' : 'Reservation cancelled');

            $reservation->setRawAttributes($locked->getAttributes(), true);

            DB::commit();

            return CancelUnitsResult::done($reservation, $units->count(), (string) $total->toScale(2));
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }

    /**
     * "Room 106" for a unit with a room or bed on a night, otherwise its room type name.
     */
    private function roomLabel(ReservationUnit $unit): string
    {
        $night = $unit->nights->first(fn ($night): bool => $night->room !== null || $night->bed !== null);

        return $night?->room?->number !== null
            ? __('Room :number', ['number' => $night->room->number])
            : ($night?->bed?->label ?? $unit->roomType->name);
    }
}
