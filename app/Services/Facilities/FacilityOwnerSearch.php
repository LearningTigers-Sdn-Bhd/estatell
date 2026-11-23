<?php

namespace App\Services\Facilities;

use App\Data\Facilities\FacilityOwnerEventData;
use App\Data\Facilities\FacilityOwnerReservationData;
use App\Data\Reservations\ReservationUnitOptionData;
use App\Enums\EventStatus;
use App\Enums\UnitStatus;
use App\Models\Event;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Services\Guests\GuestCommandSearch;
use App\Services\Reservations\ReservationRoomBox;
use App\Services\Rooms\RoomCommandSearch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The For step of Book facility on the Timeline View: who a new booking belongs to. Only owners that take bookings
 * are offered: reservations with a Confirmed or In-house unit, and Tentative or Confirmed events on the date. The
 * booking itself still goes through `BookFacility`, which checks again. See
 * docs/33-facilities/05-timeline-and-report.md#book-facility-from-the-timeline.
 */
class FacilityOwnerSearch
{
    public const MIN_LENGTH = 2;

    private const LIMIT = 5;

    public function __construct(
        private GuestCommandSearch $guests,
        private RoomCommandSearch $rooms,
        private ReservationRoomBox $roomBox,
        private ReservationFacilityBookings $bookings,
    ) {}

    /**
     * Up to five live reservations whose reference starts with the text, or whose booker or room matches it. Nothing
     * under two characters.
     *
     * @return list<FacilityOwnerReservationData>
     */
    public function reservations(Property $property, string $text): array
    {
        $text = trim($text);

        if (mb_strlen($text) < self::MIN_LENGTH) {
            return [];
        }

        $reservations = Reservation::query()
            ->where('property_id', $property->id)
            ->whereHas('units', fn (Builder $units) => $units->whereIn('status', self::liveStatuses()))
            ->where(fn (Builder $query) => $query
                ->where('reference', 'like', addcslashes(mb_strtoupper($text), '\\%_').'%')
                ->orWhereIn('booker_guest_id', $this->guests->matching($property, $text)->select('id'))
                ->orWhereHas('units', fn (Builder $units) => $units->where('property_id', $property->id)
                    ->whereHas('nights', fn (Builder $nights) => $nights->whereIn('room_id', $this->rooms->matching($property, $text)->select('id')))))
            ->with(['booker:id,full_name', 'units' => fn ($units) => $units->whereIn('status', self::liveStatuses())->with('roomType')->orderBy('id')])
            ->orderByRaw('reference = ? desc', [mb_strtoupper($text)])
            ->orderBy('reference')
            ->limit(self::LIMIT)
            ->get();

        $assigned = $this->roomBox->assignmentsForUnits(array_values(array_map(intval(...), $reservations->flatMap->units->pluck('id')->all())));

        return array_values($reservations->map(fn (Reservation $reservation): FacilityOwnerReservationData => new FacilityOwnerReservationData(
            id: $reservation->id,
            label: collect([$reservation->booker?->full_name, $reservation->reference])->filter()->implode(' · '),
            detail: $reservation->units
                ->map(fn (ReservationUnit $unit): string => ReservationUnitOptionData::fromModel($unit, $assigned[$unit->id] ?? null)->label)
                ->push(self::dates($reservation))
                ->implode(' · '),
        ))->all());
    }

    /**
     * What the Book facility Sheet needs for a reservation picked in the For step.
     *
     * @return array<string, mixed>
     */
    public function reservation(Property $property, Reservation $reservation): array
    {
        $units = $reservation->units()->with('roomType')->orderBy('id')->get();
        $assigned = $this->roomBox->assignmentsForUnits(array_values(array_map(intval(...), $units->pluck('id')->all())));
        $labels = $units->mapWithKeys(fn (ReservationUnit $unit): array => [$unit->id => ReservationUnitOptionData::fromModel($unit, $assigned[$unit->id] ?? null)->label])->all();

        return $this->bookings->sheetProps($property, $reservation, $labels);
    }

    /**
     * The Tentative and Confirmed events on the date, by start date and name.
     *
     * @return list<FacilityOwnerEventData>
     */
    public function events(Property $property, CarbonImmutable $date): array
    {
        return array_values(Event::query()
            ->where('property_id', $property->id)
            ->whereIn('status', [EventStatus::Tentative->value, EventStatus::Confirmed->value])
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->with(['payerGuest:id,full_name', 'payerCompany:id,name'])
            ->orderBy('starts_on')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(FacilityOwnerEventData::fromModel(...))
            ->all());
    }

    /**
     * @return list<string>
     */
    private static function liveStatuses(): array
    {
        return [UnitStatus::Confirmed->value, UnitStatus::CheckedIn->value];
    }

    /**
     * The first arrival to the last departure of the loaded units, as "18–21 Oct" or "30 Oct–2 Nov".
     */
    private static function dates(Reservation $reservation): string
    {
        $from = $reservation->units->min(fn (ReservationUnit $unit): string => $unit->arrival_date->toDateString());
        $to = $reservation->units->max(fn (ReservationUnit $unit): string => $unit->departure_date->toDateString());

        if ($from === null || $to === null) {
            return '';
        }

        $start = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);

        return $start->month === $end->month
            ? $start->format('j').'–'.$end->format('j M')
            : $start->format('j M').'–'.$end->format('j M');
    }
}
