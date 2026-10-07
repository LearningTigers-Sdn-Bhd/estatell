<?php

namespace App\Actions\FrontDesk;

use App\Actions\Reservations\RemoveUnitAddOn;
use App\Models\ReservationUnit;
use App\Models\User;

class RemoveUnpostedAddOns
{
    public function __construct(private RemoveUnitAddOn $removeUnitAddOn) {}

    /**
     * Delete the add-ons of a unit that have no folio line yet, each through `RemoveUnitAddOn`, so each one is
     * logged. A posted add-on stays, and the correction is `ReverseLine`. `CancelUnit` and `MarkNoShow` call this
     * while the unit is still Confirmed, because `RemoveUnitAddOn` refuses a unit that is not open.
     *
     * @return list<int> the ids of the removed add-ons
     */
    public function __invoke(?User $actor, ReservationUnit $unit): array
    {
        $removed = [];

        foreach ($unit->addOns()->whereNull('folio_line_id')->orderBy('id')->get() as $addOn) {
            if (($this->removeUnitAddOn)($actor, $addOn)) {
                $removed[] = $addOn->id;
            }
        }

        return $removed;
    }
}
