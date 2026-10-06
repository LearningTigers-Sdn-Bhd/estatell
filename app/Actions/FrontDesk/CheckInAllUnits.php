<?php

namespace App\Actions\FrontDesk;

use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\User;

class CheckInAllUnits
{
    public function __construct(private CheckInUnit $checkInUnit) {}

    /**
     * Check in the chosen units of a reservation, one `CheckInUnit` call each. A unit that is refused does not stop
     * the others, and the refusal comes back for its own row. A unit id that is not a unit of the reservation is
     * ignored. It takes no payment and saves no nationality: staff do both per unit afterwards. `$withoutCard` is for
     * a user with `front-desk.check-in-without-card`, and a company over its credit limit refuses its units
     * (`credit_limit_exceeded`), because the override reason belongs to one unit. Permissions are checked by the
     * route. See docs/18-front-desk/01-check-in-and-check-out.md#forms-on-the-reservation-page.
     *
     * @param  list<int>  $unitIds
     * @return array<int, string|null> the refusal code of each unit, null when it was checked in
     */
    public function __invoke(User $actor, Reservation $reservation, array $unitIds, bool $withoutCard = false): array
    {
        $results = [];

        foreach ($reservation->units()->whereIn('id', $unitIds)->orderBy('id')->get() as $unit) {
            /** @var ReservationUnit $unit */
            $results[$unit->id] = ($this->checkInUnit)($actor, $unit, $withoutCard)->refusal;
        }

        return $results;
    }
}
