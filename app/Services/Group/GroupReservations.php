<?php

namespace App\Services\Group;

use App\Data\Group\GroupReservationRowData;
use App\Enums\FolioStatus;
use App\Enums\PermissionEnum;
use App\Enums\ReservationStatus;
use App\Enums\UnitStatus;
use App\Models\Guest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Staff\GroupProperties;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Group › Reservations: the bookings of every property whose role shows reservations, in one list, found by reference,
 * channel reference or booker (name, email, phone; only where the role shows guests), with a status, a stay window and a property filter. Each row has
 * its stay dates, rooms, pax, room total and folio balance from the same joins as the property's reservation list. Rows
 * come by property (by name), then by arrival, so the list shows each property under one heading.
 * Queries do not grow with the number of bookings on a page. See docs/03-staff/05-group-mode.md#reservations.
 */
class GroupReservations
{
    public const int PER_PAGE = 25;

    /** The most rows a CSV holds. */
    public const int EXPORT_LIMIT = 5000;

    public function __construct(private GroupProperties $groupProperties) {}

    /**
     * The properties whose role shows reservations, and for each whether it shows guest names.
     *
     * @param  Collection<int, Property>  $properties
     * @return array{0: Collection<int, Property>, 1: array<int, bool>, 2: list<string>}
     */
    public function scope(User $user, Collection $properties): array
    {
        $permissions = $this->groupProperties->permissionsFor($user, $properties, [PermissionEnum::ReservationsView, PermissionEnum::GuestsView]);
        $holds = fn (Property $property, PermissionEnum $permission): bool => in_array($permission->value, $permissions[$property->id] ?? [], true);
        $visible = $properties->filter(fn (Property $property): bool => $holds($property, PermissionEnum::ReservationsView))->values();
        $guests = $visible->mapWithKeys(fn (Property $property): array => [$property->id => $holds($property, PermissionEnum::GuestsView)])->all();
        $leftOut = array_values($properties->reject(fn (Property $property): bool => $visible->contains($property))->map(fn (Property $property): string => $property->name)->all());

        return [$visible, $guests, $leftOut];
    }

    /**
     * @param  Collection<int, Property>  $properties  Only properties `scope()` kept.
     * @param  array<int, bool>  $guests
     * @param  array{search: ?string, status: ?ReservationStatus, from: ?string, to: ?string, property: ?string}  $filters
     * @return LengthAwarePaginator<int, GroupReservationRowData>
     */
    public function page(Collection $properties, array $guests, array $filters): LengthAwarePaginator
    {
        return $this->query($properties, $guests, $filters)
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Reservation $reservation): GroupReservationRowData => GroupReservationRowData::fromModel($reservation, $guests[$reservation->property_id] ?? false));
    }

    /**
     * @param  Collection<int, Property>  $properties
     * @param  array<int, bool>  $guests
     * @param  array{search: ?string, status: ?ReservationStatus, from: ?string, to: ?string, property: ?string}  $filters
     * @return list<GroupReservationRowData>
     */
    public function all(Collection $properties, array $guests, array $filters): array
    {
        return array_values($this->query($properties, $guests, $filters)
            ->limit(self::EXPORT_LIMIT)
            ->get()
            ->map(fn (Reservation $reservation): GroupReservationRowData => GroupReservationRowData::fromModel($reservation, $guests[$reservation->property_id] ?? false))
            ->all());
    }

    /**
     * @param  Collection<int, Property>  $properties
     * @param  array<int, bool>  $guests
     * @param  array{search: ?string, status: ?ReservationStatus, from: ?string, to: ?string, property: ?string}  $filters
     * @return Builder<Reservation>
     */
    private function query(Collection $properties, array $guests, array $filters): Builder
    {
        $ids = $properties
            ->when($filters['property'] !== null, fn ($items) => $items->where('code', $filters['property']))
            ->modelKeys();
        $holding = array_map(fn (UnitStatus $status): string => $status->value, UnitStatus::holdingInventory());
        $holdingList = "'".implode("', '", $holding)."'";

        $units = DB::table('reservation_units')
            ->whereIn('property_id', $ids)
            ->groupBy('reservation_id')
            ->selectRaw("reservation_id,
                coalesce(min(arrival_date) filter (where status in ({$holdingList})), min(arrival_date)) as first_arrival,
                coalesce(max(departure_date) filter (where status in ({$holdingList})), max(departure_date)) as last_departure,
                count(*) filter (where status in ({$holdingList})) as live_units,
                coalesce(sum(adults) filter (where status in ({$holdingList})), 0) as adults,
                coalesce(sum(children) filter (where status in ({$holdingList})), 0) as children");
        $nightAmounts = DB::table('unit_nights')
            ->join('reservation_units', 'reservation_units.id', '=', 'unit_nights.reservation_unit_id')
            ->whereIn('reservation_units.property_id', $ids)
            ->whereIn('reservation_units.status', $holding)
            ->select('reservation_units.reservation_id', 'unit_nights.amount');
        $dayUseAmounts = DB::table('unit_day_uses')
            ->join('reservation_units', 'reservation_units.id', '=', 'unit_day_uses.reservation_unit_id')
            ->whereIn('reservation_units.property_id', $ids)
            ->whereIn('reservation_units.status', $holding)
            ->select('reservation_units.reservation_id', 'unit_day_uses.amount');
        $totals = DB::query()
            ->fromSub($nightAmounts->unionAll($dayUseAmounts), 'amounts')
            ->groupBy('amounts.reservation_id')
            ->selectRaw('amounts.reservation_id, sum(amounts.amount) as total');
        $ledger = DB::table('folios')
            ->leftJoin('folio_lines', 'folio_lines.folio_id', '=', 'folios.id')
            ->whereIn('folios.property_id', $ids)
            ->where('folios.status', '!=', FolioStatus::Voided->value)
            ->groupBy('folios.reservation_id')
            ->selectRaw('folios.reservation_id, coalesce(sum(folio_lines.amount), 0)::numeric(14, 2) as balance');

        $query = Reservation::query()
            ->whereIn('reservations.property_id', $ids)
            ->leftJoinSub($units, 'stay', 'stay.reservation_id', '=', 'reservations.id')
            ->leftJoinSub($totals, 'totals', 'totals.reservation_id', '=', 'reservations.id')
            ->leftJoinSub($ledger, 'ledger', 'ledger.reservation_id', '=', 'reservations.id')
            ->select([
                'reservations.*',
                'stay.first_arrival', 'stay.last_departure', 'stay.live_units', 'stay.adults', 'stay.children',
                DB::raw('coalesce(totals.total, 0) as stay_total'),
                DB::raw('coalesce(ledger.balance, 0)::numeric(14, 2) as balance'),
            ])
            ->with(['property.settings', 'booker', 'bookingSource'])
            ->orderBy(Property::query()->select('name')->whereColumn('properties.id', 'reservations.property_id'))
            ->orderBy('reservations.property_id')
            ->orderBy('stay.first_arrival')
            ->orderBy('reservations.id');

        if ($filters['status'] !== null) {
            $query->where('reservations.status', $filters['status']->value);
        }

        if ($filters['from'] !== null) {
            $query->where('stay.last_departure', '>=', $filters['from']);
        }

        if ($filters['to'] !== null) {
            $query->where('stay.first_arrival', '<=', $filters['to']);
        }

        $search = trim((string) $filters['search']);

        if ($search !== '') {
            $guestPropertyIds = array_keys(array_filter($guests));

            $query->where(function (Builder $query) use ($search, $guestPropertyIds): void {
                $query->whereLike('reservations.reference', "%{$search}%")
                    ->orWhereLike('reservations.short_code', "%{$search}%")
                    ->orWhereLike('reservations.external_reference', "%{$search}%")
                    ->orWhereIn('reservations.booker_guest_id', Guest::query()->select('guests.id')->whereIn('guests.property_id', $guestPropertyIds)->matchingText($search));
            });
        }

        return $query;
    }
}
