<?php

namespace App\Services\Portal;

use App\Data\Portal\PortalBookingData;
use App\Data\Portal\PortalBookingRowData;
use App\Enums\CompanyStatus;
use App\Enums\PortalCancelMode;
use App\Enums\UnitStatus;
use App\Models\Company;
use App\Models\Property;
use App\Models\Reservation;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Reads the bookings of one company at one property for the portal. Every query is scoped by the property and the
 * company, so another company's or property's booking is never found. The list takes a fixed number of queries
 * whatever the page holds. See docs/23-company-portal/01-company-portal.md#bookings.
 */
class PortalBookings
{
    public const int PER_PAGE = 20;

    public function __construct(private PortalBookingRules $rules) {}

    /**
     * The company's bookings, newest first, 20 to a page. The search matches our reference or the company's own
     * reference as typed text.
     *
     * @return LengthAwarePaginator<int, PortalBookingRowData>
     */
    public function paginate(Company $company, ?string $search): LengthAwarePaginator
    {
        $search = trim((string) $search);

        $page = $this->forCompany($company)
            ->with('booker:id,full_name')
            ->withCount('units')
            ->withMin('units as first_arrival', 'arrival_date')
            ->withMax('units as last_departure', 'departure_date')
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->whereLike('reservations.reference', "%{$search}%")
                ->orWhereLike('reservations.external_reference', "%{$search}%")))
            ->orderByDesc('reservations.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $totals = $this->totals(array_values($page->getCollection()->modelKeys()));

        return $page->through(fn (Reservation $reservation): PortalBookingRowData => PortalBookingRowData::fromReservation($reservation, $totals[$reservation->id] ?? '0.00'));
    }

    /**
     * One booking of the company, or a 404 for any other.
     */
    public function find(Company $company, int $reservationId): Reservation
    {
        return $this->forCompany($company)->findOrFail($reservationId);
    }

    public function detail(Company $company, Reservation $reservation, Property $property): PortalBookingData
    {
        $reservation->load([
            'booker.documents:id,guest_id,type',
            'units' => fn ($units) => $units->orderBy('id'),
            'units.roomType:id,name',
            'units.ratePlan:id,name',
            'units.nights:id,reservation_unit_id,amount',
            'units.dayUse:id,reservation_unit_id,amount',
            'units.guests.guest.documents:id,guest_id,type',
            'units.addOns.chargeItem:id,name',
        ]);

        $businessDate = $this->rules->businessDate($property);
        $suspended = $company->status === CompanyStatus::Suspended;
        $freeUntil = $this->rules->freeUntil($reservation, $company);

        return PortalBookingData::fromReservation(
            $reservation,
            canEditGuests: ! $suspended && $this->rules->canEditGuests($reservation, $businessDate),
            cancelMode: $suspended ? PortalCancelMode::Closed : $this->rules->cancelMode($reservation, $company, $businessDate),
            freeUntil: $freeUntil?->toDateString(),
        );
    }

    /**
     * @return Builder<Reservation>
     */
    private function forCompany(Company $company): Builder
    {
        return Reservation::query()
            ->where('reservations.property_id', $company->property_id)
            ->where('reservations.company_id', $company->id);
    }

    /**
     * The rooms and add-ons of the live units (not Cancelled or NoShow) of each reservation, as decimal strings.
     * Three queries for the whole page.
     *
     * @param  list<int|string>  $reservationIds
     * @return array<int, string>
     */
    private function totals(array $reservationIds): array
    {
        if ($reservationIds === []) {
            return [];
        }

        $live = [UnitStatus::Cancelled->value, UnitStatus::NoShow->value];
        $totals = [];

        $sums = [
            DB::table('unit_nights')->join('reservation_units', 'reservation_units.id', '=', 'unit_nights.reservation_unit_id')->selectRaw('reservation_units.reservation_id, SUM(unit_nights.amount) AS total'),
            DB::table('unit_day_uses')->join('reservation_units', 'reservation_units.id', '=', 'unit_day_uses.reservation_unit_id')->selectRaw('reservation_units.reservation_id, SUM(unit_day_uses.amount) AS total'),
            DB::table('unit_add_ons')->join('reservation_units', 'reservation_units.id', '=', 'unit_add_ons.reservation_unit_id')->selectRaw('reservation_units.reservation_id, SUM(unit_add_ons.quantity * unit_add_ons.unit_amount) AS total'),
        ];

        foreach ($sums as $query) {
            $rows = $query->whereIn('reservation_units.reservation_id', $reservationIds)->whereNotIn('reservation_units.status', $live)->groupBy('reservation_units.reservation_id')->get();

            foreach ($rows as $row) {
                $totals[(int) $row->reservation_id] = (string) BigDecimal::of($totals[(int) $row->reservation_id] ?? '0')->plus((string) $row->total)->toScale(2);
            }
        }

        return $totals;
    }
}
