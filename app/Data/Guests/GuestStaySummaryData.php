<?php

namespace App\Data\Guests;

use App\Enums\StayType;
use App\Enums\UnitStatus;
use App\Models\ReservationUnit;
use App\Services\Guests\GuestStayHistory;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Spatie\LaravelData\Data;

/**
 * The stay figures in the guest page header, from the units of `GuestStayHistory::stays()` (newest arrival first).
 * See docs/11-guests/01-guest-profiles.md#stay-history.
 */
class GuestStaySummaryData extends Data
{
    public function __construct(
        public int $stays,
        public int $staysLastYear,
        public int $nights,
        public int $dayUses,
        public string $spend,
        public ?string $lastVisit,
        public bool $inHouse,
        public ?string $source,
        public ?string $sourceKind,
    ) {}

    /**
     * @param  Collection<int, ReservationUnit>  $stays
     * @param  CarbonInterface  $since  The property's today minus one year.
     */
    public static function fromStays(Collection $stays, CarbonInterface $since): self
    {
        $history = app(GuestStayHistory::class);
        $latest = $stays->first()?->reservation;

        return new self(
            stays: $stays->count(),
            staysLastYear: $stays->filter(fn (ReservationUnit $unit): bool => $unit->departure_date->greaterThanOrEqualTo($since->startOfDay()))->count(),
            nights: $stays->sum(fn (ReservationUnit $unit): int => $history->nights($unit)),
            dayUses: $stays->where('stay_type', StayType::DayUse)->count(),
            spend: (string) $history->totalSpend($stays),
            lastVisit: $stays->where('status', UnitStatus::CheckedOut)->max(fn (ReservationUnit $unit): string => $unit->departure_date->toDateString()),
            inHouse: $stays->contains('status', UnitStatus::CheckedIn),
            source: $latest?->company->name ?? $latest?->bookingSource?->name,
            sourceKind: $latest === null ? null : ($latest->company?->type->label() ?? __('Booking source')),
        );
    }
}
