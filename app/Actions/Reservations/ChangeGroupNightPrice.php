<?php

namespace App\Actions\Reservations;

use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\Reservations\ReservationRoomBox;
use Illuminate\Support\Facades\DB;
use Throwable;

class ChangeGroupNightPrice
{
    public function __construct(
        private ChangeNightPrice $changeNightPrice,
        private ReservationRoomBox $roomBox,
    ) {}

    /**
     * Set one price per night on every night of each picked unit, all or nothing. The units are locked in id order,
     * then `ChangeNightPrice` runs once per unit with all of its nights, and the first refusal rolls every unit back
     * and names its unit. A unit id that is not a unit of the reservation is ignored, and no unit left is refused
     * (`no_units_selected`). Each unit keeps its own `reservation.night_price_changed` entry. The route checks
     * `reservations.change-price`. See docs/12-reservations/09-change-night-price.md#change-the-price-of-a-group.
     *
     * @param  list<int>  $unitIds
     */
    public function __invoke(User $actor, Reservation $reservation, array $unitIds, string $price, string $reason): GroupNightPriceResult
    {
        DB::beginTransaction();

        try {
            $units = $reservation->units()->whereIn('id', $unitIds)->with('roomType')->orderBy('id')->lockForUpdate()->get();

            if ($units->isEmpty()) {
                DB::rollBack();

                return GroupNightPriceResult::refused(GroupNightPriceResult::REFUSAL_NO_UNITS);
            }

            $changed = 0;
            $corrected = 0;

            foreach ($units as $unit) {
                /** @var ReservationUnit $unit */
                $nights = array_values($unit->nights()->orderBy('date')->get()->all());
                $result = ($this->changeNightPrice)($actor, $unit, $nights, $price, $reason);

                if ($result->isRefused()) {
                    DB::rollBack();

                    return GroupNightPriceResult::refused((string) $result->refusal, $unit->id, $this->label($unit));
                }

                $changed += $result->changed;
                $corrected += $result->corrected;
            }

            DB::commit();

            return GroupNightPriceResult::done($units->count(), $changed, $corrected);
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
