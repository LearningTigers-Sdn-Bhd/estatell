<?php

namespace App\Services\Commissions;

use App\Data\Commissions\CommissionBookingFilters;
use App\Data\Commissions\CommissionBookingRowData;
use App\Data\Property\CurrencyData;
use App\Enums\CommissionEntryType;
use App\Enums\CommissionService;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The rows of the admin Commissions and the property Payouts Bookings tabs: one per reservation with ledger entries due in the period, newest due
 * first. The value is what Estatell collected for a booking engine booking, or the commission base otherwise (reversals
 * cancel out). See docs/30-commission-fees/05-platform-pages.md#bookings-tab.
 */
class CommissionBookings
{
    public const int PER_PAGE = 25;

    /**
     * @return LengthAwarePaginator<int, CommissionBookingRowData>
     */
    public function paginate(CommissionBookingFilters $filters): LengthAwarePaginator
    {
        $page = $this->grouped($filters)->paginate(self::PER_PAGE)->withQueryString();

        return $page->setCollection($this->rows(collect($page->items())));
    }

    /**
     * Every row, for the export.
     *
     * @return Collection<int, CommissionBookingRowData>
     */
    public function all(CommissionBookingFilters $filters): Collection
    {
        return $this->grouped($filters)->get()->chunk(200)->flatMap(fn (Collection $chunk): Collection => $this->rows($chunk))->values();
    }

    /**
     * The sums of every row the filters keep, not only one page.
     *
     * @return array{value: string, commissions: array<string, string>, outstanding: string}
     */
    public function totals(CommissionBookingFilters $filters): array
    {
        $sums = ['value' => BigDecimal::zero(), 'outstanding' => BigDecimal::zero()];

        foreach (CommissionService::cases() as $service) {
            $sums['commission_'.$service->value] = BigDecimal::zero();
        }

        foreach ($this->grouped($filters)->get() as $row) {
            foreach (array_keys($sums) as $key) {
                $sums[$key] = $sums[$key]->plus((string) (((array) $row)[$key] ?? 0));
            }
        }

        return [
            'value' => $this->money($sums['value']),
            'commissions' => $this->commissions($sums),
            'outstanding' => $this->money($sums['outstanding']),
        ];
    }

    private function grouped(CommissionBookingFilters $filters): Builder
    {
        $query = DB::table('commission_entries')
            ->whereNotNull('reservation_id')
            ->whereBetween('due_date', [$filters->from, $filters->to])
            ->when($filters->propertyId !== null, fn (Builder $query) => $query->where('property_id', $filters->propertyId))
            ->when($filters->search !== null, fn (Builder $query) => $query->whereIn('reservation_id', Reservation::query()
                ->select('id')
                ->where(fn (EloquentBuilder $reservations) => $reservations
                    ->where('reference', 'ilike', '%'.$filters->search.'%')
                    ->orWhereHas('booker', fn (EloquentBuilder $booker) => $booker->where('full_name', 'ilike', '%'.$filters->search.'%')))))
            ->groupBy('reservation_id')
            ->select('reservation_id')
            ->selectRaw('max(due_date) as last_due')
            ->selectRaw('sum(amount) as outstanding')
            ->selectRaw('case when sum(case when type = ? then 1 else 0 end) > 0 then sum(case when type = ? then amount else 0 end) else coalesce(sum(case when type = ? then base_amount else 0 end), 0) end as value', [
                CommissionEntryType::Collected->value,
                CommissionEntryType::Collected->value,
                CommissionEntryType::Commission->value,
            ]);

        foreach (CommissionService::cases() as $service) {
            $query->selectRaw("-sum(case when type = ? and service = ? then amount else 0 end) as commission_{$service->value}", [CommissionEntryType::Commission->value, $service->value]);
        }

        if ($filters->service !== null) {
            $query->havingRaw('sum(case when type = ? and service = ? then 1 else 0 end) > 0', [CommissionEntryType::Commission->value, $filters->service->value]);
        }

        return $query->orderByDesc('last_due')->orderByDesc('reservation_id');
    }

    /**
     * @param  Collection<int, stdClass>  $grouped
     * @return Collection<int, CommissionBookingRowData>
     */
    private function rows(Collection $grouped): Collection
    {
        $reservations = Reservation::query()
            ->with(['property:id,name', 'property.settings', 'bookingSource:id,name', 'booker:id,full_name', 'units:id,reservation_id,arrival_date,departure_date'])
            ->whereIn('id', $grouped->pluck('reservation_id'))
            ->get()
            ->keyBy('id');

        return $grouped->map(function (stdClass $row) use ($reservations): CommissionBookingRowData {
            /** @var Reservation $reservation */
            $reservation = $reservations[$row->reservation_id];
            $units = $reservation->units;

            return new CommissionBookingRowData(
                reservationId: $reservation->id,
                propertyName: $reservation->property->name,
                bookingReference: $reservation->reference,
                sourceName: $reservation->bookingSource->name,
                bookedAt: $reservation->created_at->format('Y-m-d H:i'),
                checkIn: $units->min(fn (ReservationUnit $unit): string => $unit->arrival_date->toDateString()),
                checkOut: $units->max(fn (ReservationUnit $unit): string => $unit->departure_date->toDateString()),
                guestName: $reservation->booker->full_name,
                value: $this->money($row->value),
                commissions: $this->commissions((array) $row),
                outstanding: $this->money($row->outstanding),
                currency: CurrencyData::forProperty($reservation->property),
            );
        })->values();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    private function commissions(array $row): array
    {
        return collect(CommissionService::cases())
            ->mapWithKeys(fn (CommissionService $service): array => [$service->value => $this->money($row['commission_'.$service->value] ?? 0)])
            ->all();
    }

    private function money(mixed $amount): string
    {
        return (string) BigDecimal::of($amount instanceof BigDecimal ? $amount : (string) ($amount ?? 0))->toScale(2);
    }
}
