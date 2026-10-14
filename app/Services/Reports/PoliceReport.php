<?php

namespace App\Services\Reports;

use App\Data\Reports\PoliceReportRow;
use App\Data\Reports\ReportFilters;
use App\Models\Guest;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\UnitGuest;
use App\Services\Guests\CountryOptions;
use App\Services\Reservations\ReservationRoomBox;
use App\Services\Reservations\UnitNationality;
use Illuminate\Support\Collection;

/**
 * The guest register for the police: one row for each guest attached to a reservation unit that stayed in the date
 * range, or one row for the booker when the unit has no attached guest. Checked in and Checked out units only, of every
 * nationality; `foreignOnly` keeps the guests whose own nationality is set and is not `MY`. Country names, not codes.
 * The ID number is masked unless the user has `guests.view-documents`. See docs/22-reports/01-core-reports.md.
 */
class PoliceReport implements Report
{
    public function __construct(
        private StayUnits $stays,
        private GuestIdentities $identities,
        private ReservationRoomBox $roomBox,
        private CountryOptions $countries,
    ) {}

    /**
     * @param  list<int|string>|null  $rowKeys  `{unit id}:{guest id}`.
     * @return list<PoliceReportRow>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        $units = $this->stays->between($property, $filters, $rowKeys === null ? null : array_values(array_unique(array_map(fn (int|string $key): int => (int) explode(':', (string) $key)[0], $rowKeys))));
        $wanted = $rowKeys === null ? null : array_map('strval', $rowKeys);

        /** @var list<array{0: ReservationUnit, 1: Guest}> $pairs */
        $pairs = [];

        foreach ($units as $unit) {
            foreach ($this->guestsOf($unit) as $guest) {
                $isWanted = $wanted === null || in_array($unit->id.':'.$guest->id, $wanted, true);

                if ($isWanted && (! $filters->foreignOnly || UnitNationality::isForeign($guest->nationality_code))) {
                    $pairs[] = [$unit, $guest];
                }
            }
        }

        if ($pairs === []) {
            return [];
        }

        $rooms = $this->roomBox->assignmentsForUnits(array_values(array_unique(array_map(fn (array $pair): int => $pair[0]->id, $pairs))));
        $identities = $this->identities->partsForGuests(array_map(fn (array $pair): int => $pair[1]->id, $pairs));
        $timeZone = $property->loadMissing('settings')->settings->time_zone ?? config('app.timezone');

        return array_map(fn (array $pair): PoliceReportRow => PoliceReportRow::fromGuest(
            $pair[0],
            $pair[1],
            $rooms[$pair[0]->id] ?? null,
            $this->countries->name($pair[1]->nationality_code) ?? '',
            GuestIdentities::text($identities[$pair[1]->id] ?? null),
            $identities[$pair[1]->id]['type'] ?? '',
            $identities[$pair[1]->id]['number'] ?? '',
            $this->address($pair[1]),
            $pair[0]->checked_in_at,
            $pair[0]->checked_out_at,
            $timeZone,
        ), $pairs);
    }

    /**
     * The guests attached to the unit, or the booker when there are none.
     *
     * @return Collection<int, Guest>
     */
    private function guestsOf(ReservationUnit $unit): Collection
    {
        $guests = $unit->guests->sortBy('id')->map(fn (UnitGuest $attached): Guest => $attached->guest)->values();

        return $guests->isEmpty() ? collect([$unit->reservation->booker]) : $guests;
    }

    /**
     * The address fields and the country name on one line.
     */
    private function address(Guest $guest): string
    {
        return collect([
            $guest->address_line1,
            $guest->address_line2,
            trim(($guest->postal_code ?? '').' '.($guest->city ?? '')),
            $guest->state,
            $this->countries->name($guest->country_code),
        ])->filter(fn (?string $part): bool => filled($part))->implode(', ');
    }
}
