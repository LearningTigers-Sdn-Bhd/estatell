<?php

namespace App\Actions\FrontDesk;

use App\Data\Reservations\CollectNowData;
use App\Enums\EndedStayChoice;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\FrontDesk\EndedStayCheckIn;
use App\Services\NightAudit\CurrentBusinessDate;

class CheckInAllUnits
{
    public function __construct(
        private CheckInAndCollect $checkInAndCollect,
        private CheckInEndedStay $checkInEndedStay,
        private EndedStayCheckIn $endedStayCheckIn,
        private CurrentBusinessDate $currentBusinessDate,
    ) {}

    /**
     * Check in the chosen units of a reservation, one `CheckInAndCollect` call each, each in its own transaction. A
     * unit that is refused does not stop the others, and its result comes back for its own row. A unit id that is not
     * a unit of the reservation is ignored. With `$collect`, each unit takes its own Collect now payments with the same
     * method and reference, keyed `{uuid}:{unit id}`, so a refused unit takes no money and a repeat of the request
     * returns the payments that exist. It saves no nationality: a unit that needs one is refused
     * (`nationality_required`) and is checked in on its own. `$withoutCard` is for a user with
     * `front-desk.check-in-without-card`, and a company over its credit limit refuses its units
     * (`credit_limit_exceeded`), because the override reason belongs to one unit. Permissions are checked by the
     * route. `$endedStayChoice` applies to the units `EndedStayCheckIn` offers, through `CheckInEndedStay`, one unit at a
     * time. See docs/18-front-desk/01-check-in-and-check-out.md#forms-on-the-reservation-page.
     *
     * @param  list<int>  $unitIds
     * @return array<int, CheckInAndCollectResult> the result of each unit, keyed by unit id
     */
    public function __invoke(User $actor, Reservation $reservation, array $unitIds, bool $withoutCard = false, ?EndedStayChoice $endedStayChoice = null, ?string $arrivedAt = null, ?CollectNowData $collect = null): array
    {
        $results = [];
        $businessDate = $this->currentBusinessDate->date($reservation->property);

        foreach ($reservation->units()->whereIn('id', $unitIds)->orderBy('id')->get() as $unit) {
            /** @var ReservationUnit $unit */
            $isEndedStay = $endedStayChoice !== null && $businessDate !== null && $this->endedStayCheckIn->isOffered($unit, $businessDate);
            $unitCollect = $collect === null ? null : new CollectNowData(
                taxes: $collect->taxes,
                deposit: $collect->deposit,
                paymentMethodId: $collect->paymentMethodId,
                reference: $collect->reference,
                idempotencyKey: $collect->idempotencyKey.':'.$unit->id,
                roomCharges: $collect->roomCharges,
                taxIds: $collect->taxIds,
                chargeCodeIds: $collect->chargeCodeIds,
            );
            $results[$unit->id] = $isEndedStay
                ? ($this->checkInEndedStay)($actor, $unit, $endedStayChoice, $arrivedAt, withoutCard: $withoutCard, collect: $unitCollect)
                : ($this->checkInAndCollect)($actor, $unit, null, $withoutCard, null, $unitCollect);
        }

        return $results;
    }
}
