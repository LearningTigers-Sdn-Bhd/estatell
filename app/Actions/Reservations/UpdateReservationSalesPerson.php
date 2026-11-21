<?php

namespace App\Actions\Reservations;

use App\Models\Reservation;
use App\Models\User;
use App\Services\Reservations\SalesPeople;
use Illuminate\Support\Facades\DB;

class UpdateReservationSalesPerson
{
    public function __construct(private SalesPeople $salesPeople) {}

    /**
     * Set or clear the sales person. It works on closed reservations too, because it changes no money. Refused when the
     * user is not an active staff user of the property (`sales_person_not_available`). The same person is not a change:
     * nothing is written or logged, so a deactivated sales person can stay on the bookings they sold.
     * See docs/12-reservations/12-sales-person.md.
     */
    public function __invoke(User $actor, Reservation $reservation, ?int $salesPersonId): ReservationResult
    {
        return DB::transaction(function () use ($actor, $reservation, $salesPersonId): ReservationResult {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);

            if ($locked->sales_person_id === $salesPersonId) {
                return ReservationResult::created($reservation);
            }

            if ($salesPersonId !== null && ! $this->salesPeople->isAvailable($locked->property_id, $salesPersonId)) {
                return ReservationResult::refused(ReservationResult::REFUSAL_SALES_PERSON_NOT_AVAILABLE);
            }

            $oldSalesPersonId = $locked->sales_person_id;
            $locked->sales_person_id = $salesPersonId;
            $locked->save();

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('reservation.sales_person_updated')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'old' => ['sales_person_id' => $oldSalesPersonId],
                    'new' => ['sales_person_id' => $salesPersonId],
                ])
                ->log('Reservation sales person updated');

            $reservation->setRawAttributes($locked->getAttributes(), true);

            return ReservationResult::created($reservation);
        });
    }
}
