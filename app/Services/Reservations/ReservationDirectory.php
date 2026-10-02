<?php

namespace App\Services\Reservations;

use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Enums\UnitStatus;
use App\Models\Guest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\UnitGuest;
use App\Services\Guests\GuestMatcher;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Property\PropertyToday;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The reservation list of one property: search, filters, sorts, pagination and status counts from the URL.
 *
 * Each row carries the dates, unit counts, occupancy and total of the units that hold inventory (dates fall back to
 * all units when none does), from two grouped sub-queries joined once. See docs/12-reservations/06-reservation-page.md.
 */
class ReservationDirectory
{
    public const int PER_PAGE = 24;

    public const string STAY_ALL = 'all';

    /**
     * Sort names and the column each one sorts by.
     */
    private const array SORTS = [
        'arrival' => 'stay.first_arrival',
        'departure' => 'stay.last_departure',
        'reference' => 'reservations.reference',
        'total' => 'stay_total',
        'booked' => 'reservations.created_at',
    ];

    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private PropertyToday $propertyToday,
        private GuestMatcher $guestMatcher,
    ) {}

    /**
     * The first date of the default stay filter: the business date, or today before the property goes live.
     */
    public function defaultStayFrom(Property $property): CarbonImmutable
    {
        return $this->currentBusinessDate->date($property) ?? $this->propertyToday->date($property);
    }

    /**
     * The stay filter of the request. `mode` is `default` (from the default date, no end), `all` or `custom`.
     * A custom value is `from_to`, and either date may be empty.
     *
     * @return array{mode: 'default'|'all'|'custom', from: ?string, to: ?string}
     */
    public function stay(Property $property, Request $request): array
    {
        $value = $request->input('filter.stay');
        $default = ['mode' => 'default', 'from' => $this->defaultStayFrom($property)->toDateString(), 'to' => null];

        if (! is_string($value) || $value === '') {
            return $default;
        }

        if ($value === self::STAY_ALL) {
            return ['mode' => 'all', 'from' => null, 'to' => null];
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})?_(\d{4}-\d{2}-\d{2})?$/', $value, $match) !== 1 || ($match[1] === '' && ($match[2] ?? '') === '')) {
            return $default;
        }

        return ['mode' => 'custom', 'from' => $match[1] ?: null, 'to' => ($match[2] ?? '') ?: null];
    }

    /**
     * @return LengthAwarePaginator<int, Reservation>
     */
    public function paginate(Property $property, Request $request): LengthAwarePaginator
    {
        $base = $this->base($property)->select([
            'reservations.*',
            'stay.first_arrival',
            'stay.last_departure',
            'stay.live_units',
            'stay.all_units',
            'stay.adults',
            'stay.children',
            DB::raw('coalesce(totals.total, 0) as stay_total'),
        ]);
        $this->applyStay($base, $this->stay($property, $request));

        return QueryBuilder::for($base, $request)
            ->allowedFilters(
                AllowedFilter::callback('status', fn (Builder $query, mixed $value) => $this->applyStatus($query, $value)),
                AllowedFilter::callback('source', fn (Builder $query, mixed $value) => $this->applySource($query, $value)),
                AllowedFilter::callback('search', fn (Builder $query, mixed $value) => $this->applySearch($query, $property, $value)),
                AllowedFilter::callback('stay', fn () => null),
            )
            ->allowedSorts(...array_map(
                fn (string $name): AllowedSort => $this->sort($name),
                array_keys(self::SORTS),
            ))
            ->defaultSort($this->sort('arrival'))
            ->orderBy('reservations.id')
            ->with('booker')
            ->with(['units' => fn ($units) => $units->orderBy('id'), 'units.roomType'])
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * One count per status for the reservations that match every filter except the status.
     *
     * @return array<string, int> By status value
     */
    public function statusCounts(Property $property, Request $request): array
    {
        $query = $this->base($property);

        $this->applySource($query, $request->input('filter.source'));
        $this->applySearch($query, $property, $request->input('filter.search'));
        $this->applyStay($query, $this->stay($property, $request));

        return $query->toBase()
            ->select('reservations.status', DB::raw('count(*) as total'))
            ->groupBy('reservations.status')
            ->pluck('total', 'status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * The reservations whose booker has another Completed reservation with a lower id (a repeat guest). One query.
     *
     * @param  Collection<int, Reservation>  $reservations
     * @return list<int> Reservation ids
     */
    public function repeatReservationIds(Property $property, Collection $reservations): array
    {
        if ($reservations->isEmpty()) {
            return [];
        }

        $firstCompleted = DB::table('reservations')
            ->where('property_id', $property->id)
            ->where('status', ReservationStatus::Completed->value)
            ->whereIn('booker_guest_id', $reservations->pluck('booker_guest_id')->unique()->all())
            ->groupBy('booker_guest_id')
            ->selectRaw('booker_guest_id, min(id) as first_id')
            ->pluck('first_id', 'booker_guest_id');

        return $reservations
            ->filter(fn (Reservation $reservation): bool => $firstCompleted->has($reservation->booker_guest_id) && (int) $firstCompleted[$reservation->booker_guest_id] < $reservation->id)
            ->pluck('id')
            ->values()
            ->all();
    }

    /**
     * Reservations of the property joined once to their unit and total aggregates.
     *
     * @return Builder<Reservation>
     */
    private function base(Property $property): Builder
    {
        $holding = "'".implode("', '", array_map(fn (UnitStatus $status): string => $status->value, UnitStatus::holdingInventory()))."'";

        $units = DB::table('reservation_units')
            ->where('property_id', $property->id)
            ->groupBy('reservation_id')
            ->selectRaw("reservation_id,
                coalesce(min(arrival_date) filter (where status in ({$holding})), min(arrival_date)) as first_arrival,
                coalesce(max(departure_date) filter (where status in ({$holding})), max(departure_date)) as last_departure,
                count(*) filter (where status in ({$holding})) as live_units,
                count(*) as all_units,
                coalesce(sum(adults) filter (where status in ({$holding})), 0) as adults,
                coalesce(sum(children) filter (where status in ({$holding})), 0) as children");

        $totals = DB::table('unit_nights')
            ->join('reservation_units', 'reservation_units.id', '=', 'unit_nights.reservation_unit_id')
            ->where('reservation_units.property_id', $property->id)
            ->whereIn('reservation_units.status', array_map(fn (UnitStatus $status): string => $status->value, UnitStatus::holdingInventory()))
            ->groupBy('reservation_units.reservation_id')
            ->selectRaw('reservation_units.reservation_id, sum(unit_nights.amount) as total');

        return Reservation::query()
            ->where('reservations.property_id', $property->id)
            ->leftJoinSub($units, 'stay', 'stay.reservation_id', '=', 'reservations.id')
            ->leftJoinSub($totals, 'totals', 'totals.reservation_id', '=', 'reservations.id');
    }

    private function sort(string $name): AllowedSort
    {
        $column = self::SORTS[$name];

        return AllowedSort::callback($name, fn (Builder $query, bool $descending) => $query->orderBy($column, $descending ? 'desc' : 'asc'));
    }

    /**
     * @param  Builder<Reservation>  $query
     */
    private function applyStatus(Builder $query, mixed $value): void
    {
        $status = is_string($value) ? ReservationStatus::tryFrom($value) : null;

        if ($status !== null) {
            $query->where('reservations.status', $status);
        }
    }

    /**
     * @param  Builder<Reservation>  $query
     */
    private function applySource(Builder $query, mixed $value): void
    {
        $source = is_string($value) ? ReservationSource::tryFrom($value) : null;

        if ($source !== null) {
            $query->where('reservations.source', $source);
        }
    }

    /**
     * Matches the reference, the external reference, and the name, email or phone of the booker or any unit guest.
     * A local phone number also matches the stored E.164 number.
     *
     * @param  Builder<Reservation>  $query
     */
    private function applySearch(Builder $query, Property $property, mixed $value): void
    {
        $search = is_string($value) ? trim($value) : '';

        if ($search === '') {
            return;
        }

        $phone = $this->guestMatcher->normalizePhone($search, $property);
        $guests = fn (): Builder => Guest::query()
            ->select('guests.id')
            ->where('guests.property_id', $property->id)
            ->where(function (Builder $guests) use ($search, $phone): void {
                $guests->matchingText($search);

                if ($phone !== null) {
                    $guests->orWhere('guests.phone', $phone);
                }
            });

        $query->where(function (Builder $query) use ($search, $guests): void {
            $query->whereLike('reservations.reference', "%{$search}%")
                ->orWhereLike('reservations.external_reference', "%{$search}%")
                ->orWhereIn('reservations.booker_guest_id', $guests())
                ->orWhereIn('reservations.id', ReservationUnit::query()
                    ->select('reservation_units.reservation_id')
                    ->whereIn('reservation_units.id', UnitGuest::query()->select('unit_guests.reservation_unit_id')->whereIn('unit_guests.guest_id', $guests())));
        });
    }

    /**
     * A stay overlaps the range when it arrives on or before the last date and departs after the first date.
     *
     * @param  Builder<Reservation>  $query
     * @param  array{mode: string, from: ?string, to: ?string}  $stay
     */
    private function applyStay(Builder $query, array $stay): void
    {
        if ($stay['from'] !== null) {
            $query->where('stay.last_departure', '>', $stay['from']);
        }

        if ($stay['to'] !== null) {
            $query->where('stay.first_arrival', '<=', $stay['to']);
        }
    }
}
