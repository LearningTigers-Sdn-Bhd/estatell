<?php

namespace App\Actions\Reservations;

use App\Data\Reservations\NewAddOnData;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\Reservations\ReservationRoomBox;
use Illuminate\Support\Facades\DB;
use Throwable;

class AddGroupAddOn
{
    public function __construct(
        private AddUnitAddOn $addUnitAddOn,
        private ReservationRoomBox $roomBox,
    ) {}

    /**
     * Add one charge item to each picked unit of a reservation, all or nothing. The units are locked in id order, then
     * `AddUnitAddOn` runs once per unit with the same data, and the first refusal rolls every unit back and names its
     * unit. A unit id that is not a unit of the reservation is ignored, and no unit left is refused
     * (`no_units_selected`). Each unit keeps its own `reservation.add_on_added` entry. The route checks
     * `reservations.manage`. See docs/12-reservations/04-add-ons.md#group-add-on.
     *
     * @param  list<int>  $unitIds
     */
    public function __invoke(?User $actor, Reservation $reservation, array $unitIds, NewAddOnData $data): GroupAddOnResult
    {
        DB::beginTransaction();

        try {
            $units = $reservation->units()->whereIn('id', $unitIds)->with('roomType')->orderBy('id')->lockForUpdate()->get();

            if ($units->isEmpty()) {
                DB::rollBack();

                return GroupAddOnResult::refused(GroupAddOnResult::REFUSAL_NO_UNITS);
            }

            $addOns = [];

            foreach ($units as $unit) {
                /** @var ReservationUnit $unit */
                $result = ($this->addUnitAddOn)($actor, $unit, $data);

                if ($result->isRefused()) {
                    DB::rollBack();

                    return GroupAddOnResult::refused((string) $result->refusal, $unit->id, $this->label($unit));
                }

                array_push($addOns, ...$result->addOns);
            }

            DB::commit();

            return GroupAddOnResult::created($addOns, $units->count());
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }

    private function label(ReservationUnit $unit): string
    {
        $assigned = $this->roomBox->assignmentsForUnits([$unit->id])[$unit->id] ?? __('Not assigned');

        return $unit->roomType->name.' · '.$assigned;
    }
}
