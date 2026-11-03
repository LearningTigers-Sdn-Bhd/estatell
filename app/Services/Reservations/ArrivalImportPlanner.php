<?php

namespace App\Services\Reservations;

use App\Data\Reservations\ArrivalImportRowData;
use App\Data\Shared\BadgeData;
use App\Enums\ArrivalImportOutcome;
use App\Enums\BoatDirection;
use App\Enums\BookingSourceType;
use App\Enums\CompanyStatus;
use App\Features\Boats;
use App\Models\BoatDeparture;
use App\Models\BookingSource;
use App\Models\Company;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\NightAudit\FirstBusinessDate;
use App\Services\Property\MigrationWindow;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Throwable;

/**
 * Plans an arrival import: matches each raw row to the property's rooms, room types, enter-price plans, sources,
 * companies and boat departures, decides what it becomes and lists its problems. Reads only.
 * See docs/12-reservations/13-arrival-import.md.
 */
class ArrivalImportPlanner
{
    private const array TITLES = ['mr', 'mrs', 'ms', 'mdm', 'miss', 'dr', 'madam', 'mister'];

    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private FirstBusinessDate $firstBusinessDate,
        private MigrationWindow $migrationWindow,
        private ReservationFormOptions $formOptions,
    ) {}

    /**
     * @param  list<array<string, string|int>>  $rawRows  Rows from `ArrivalImportReader`.
     * @return list<ArrivalImportRowData>
     */
    public function plan(Property $property, array $rawRows): array
    {
        $businessDate = $this->currentBusinessDate->date($property);
        $goLive = $this->firstBusinessDate->date($property);
        $windowOpen = $this->migrationWindow->isOpen($property);

        $roomTypes = RoomType::query()->where('property_id', $property->id)->active()->get();
        $rooms = Room::query()->where('property_id', $property->id)->get()->keyBy(fn (Room $room): string => Str::lower($room->number));
        $planIds = $this->formOptions->enteredPlanIds($property);
        $companies = Company::query()->where('property_id', $property->id)->where('status', CompanyStatus::Active)->get()->keyBy(fn (Company $company): string => Str::lower(Str::squish($company->name)));
        $sources = BookingSource::query()->where('property_id', $property->id)->where('is_active', true)->get();
        $departures = Feature::for($property)->active(Boats::class)
            ? BoatDeparture::query()->where('property_id', $property->id)->where('active', true)->get()
            : null;

        $resNos = array_map(fn (array $row): string => Str::upper((string) $row['res_no']), $rawRows);
        $counts = array_count_values(array_filter($resNos));
        $imported = Reservation::query()->where('property_id', $property->id)
            ->whereIn('external_reference', array_values(array_unique(array_map($this->groupNo(...), $resNos))))
            ->pluck('external_reference')->map(fn (string $reference): string => Str::upper($reference))->all();

        $context = compact('property', 'businessDate', 'goLive', 'windowOpen', 'roomTypes', 'rooms', 'planIds', 'companies', 'sources', 'departures', 'counts', 'imported');

        return array_map(fn (array $raw): ArrivalImportRowData => $this->row($raw, $context), $rawRows);
    }

    /**
     * `RES4840-3` belongs to group `RES4840`.
     */
    public function groupNo(string $resNo): string
    {
        return Str::upper((string) preg_replace('/-\d+$/', '', trim($resNo)));
    }

    /**
     * @param  array<string, string|int>  $raw
     * @param  array<string, mixed>  $context
     */
    private function row(array $raw, array $context): ArrivalImportRowData
    {
        $problems = [];
        $value = fn (string $key): string => trim((string) ($raw[$key] ?? ''));

        $resNo = Str::upper($value('res_no'));
        $groupNo = $this->groupNo($resNo);
        $guest = $this->guestName($value('guest'));

        foreach (['res_no' => $resNo, 'guest' => $guest] as $field => $given) {
            if ($given === '') {
                $problems[] = __('Missing :field.', ['field' => $field]);
            }
        }

        if ($resNo !== '' && ($context['counts'][$resNo] ?? 0) > 1) {
            $problems[] = __('The res no :number is in the file more than once.', ['number' => $resNo]);
        }

        [$arrival, $arrivalTime] = $this->dateTime($value('arrival'));
        [$departure, $departureTime] = $this->dateTime($value('departure'));

        if ($arrival === null) {
            $problems[] = __('Missing or unreadable arrival.');
        }

        if ($departure === null) {
            $problems[] = __('Missing or unreadable departure.');
        } elseif ($arrival !== null && $departure->lte($arrival)) {
            $problems[] = __('The departure must be after the arrival.');
        }

        $adults = (int) $value('adults');
        $children = max(0, (int) $value('children'));

        if ($adults < 1) {
            $problems[] = __('At least 1 adult.');
        }

        $total = $this->money($value('total'));
        $deposit = $this->money($value('deposit'));

        if ($total === null) {
            $problems[] = __('Missing or unreadable total.');
        }

        if ($value('deposit') !== '' && $deposit === null) {
            $problems[] = __('Unreadable deposit.');
        }

        $outcome = $this->outcome($value('status'), $arrival, $departure, $context['businessDate']);

        if (in_array(Str::upper($groupNo), $context['imported'], true) && $outcome->imports()) {
            $outcome = ArrivalImportOutcome::AlreadyImported;
        }

        $room = null;
        $roomType = null;

        if ($value('room') !== '') {
            $room = $context['rooms']->get(Str::lower($value('room')));

            if ($room === null) {
                $problems[] = __('Unknown room :room.', ['room' => $value('room')]);
            }
        }

        if ($value('room_type') !== '') {
            $roomType = $this->roomType($context['roomTypes'], $value('room_type'));

            if ($roomType === null && $room === null) {
                $problems[] = __('Unknown room type :type.', ['type' => $value('room_type')]);
            }
        }

        if ($room !== null) {
            if ($roomType !== null && $roomType->id !== $room->room_type_id) {
                $problems[] = __('Room :room is not a :type.', ['room' => $room->number, 'type' => $roomType->name]);
            }

            $roomType = $context['roomTypes']->firstWhere('id', $room->room_type_id);
        }

        if ($room === null && $roomType === null && $value('room') === '' && $value('room_type') === '') {
            $problems[] = __('Give a room or a room type.');
        }

        $planId = $roomType === null ? null : ($context['planIds'][$roomType->id] ?? null);

        if ($roomType !== null && $planId === null) {
            $problems[] = __(':type has no plan for an entered price (Per room or Per guest, open to everyone).', ['type' => $roomType->name]);
        }

        $company = null;

        if ($value('company') !== '') {
            $company = $context['companies']->get(Str::lower(Str::squish($value('company'))));

            if ($company === null) {
                $problems[] = __('Unknown company :company.', ['company' => $value('company')]);
            }
        }

        $source = $this->source($context['sources'], $value('source'), $company !== null);

        if ($source === null) {
            $problems[] = $value('source') === '' ? __('No booking source named Phone. Fill in the source.') : __('Unknown booking source :source.', ['source' => $value('source')]);
        } elseif ($source->type === BookingSourceType::Company && $company === null) {
            $problems[] = __(':source needs a company.', ['source' => $source->name]);
        }

        [$boatInId, $boatInProblem] = $this->departure($context['departures'], BoatDirection::In, $value('boat_in'));
        [$boatOutId, $boatOutProblem] = $this->departure($context['departures'], BoatDirection::Out, $value('boat_out'));
        array_push($problems, ...array_filter([$boatInProblem, $boatOutProblem]));

        if ($outcome->isPastStay()) {
            if ($room === null) {
                $problems[] = __('A past stay needs a room.');
            }

            if ($context['goLive'] !== null && $arrival !== null && $arrival->lt($context['goLive'])) {
                $problems[] = __('The arrival is before the go-live date :date.', ['date' => $context['goLive']->toDateString()]);
            }
        }

        if ($deposit !== null && $deposit !== '0.00' && $outcome->imports() && ! $context['windowOpen']) {
            $problems[] = __('The migration window is closed, so the deposit cannot be recorded.');
        }

        return new ArrivalImportRowData(
            line: (int) $raw['line'],
            resNo: $resNo,
            groupNo: $groupNo,
            guest: $guest,
            room: $room->number ?? ($value('room') ?: null),
            roomType: $roomType->name ?? ($value('room_type') ?: null),
            arrival: $arrival?->toDateString(),
            arrivalTime: $arrivalTime,
            departure: $departure?->toDateString(),
            departureTime: $departureTime,
            adults: $adults,
            children: $children,
            total: $total,
            deposit: $deposit === '0.00' ? null : $deposit,
            company: $company->name ?? ($value('company') ?: null),
            source: $source->name ?? ($value('source') ?: null),
            boatIn: $value('boat_in') ?: null,
            boatOut: $value('boat_out') ?: null,
            notes: $value('notes') ?: null,
            outcome: $outcome,
            outcomeBadge: BadgeData::fromArrivalImportOutcome($outcome),
            problems: $outcome->imports() ? $problems : [],
            roomTypeId: $roomType?->id,
            roomId: $room?->id,
            ratePlanId: $planId,
            companyId: $company?->id,
            bookingSourceId: $source?->id,
            boatInDepartureId: $boatInId,
            boatOutDepartureId: $boatOutId,
        );
    }

    /**
     * `Mr.JEROME BENGGON` becomes `JEROME BENGGON`.
     */
    private function guestName(string $name): string
    {
        $name = Str::squish($name);
        $pattern = '/^(?:'.implode('|', self::TITLES).')\.?\s*/i';

        return trim((string) preg_replace($pattern, '', $name));
    }

    /**
     * `Y-m-d`, `d/m/Y`, each with an optional time (`H:i`, `H:i:s`, `h:i:s A`).
     *
     * @return array{0: ?CarbonImmutable, 1: ?string} The date and the time (`H:i`, null when not given).
     */
    private function dateTime(string $value): array
    {
        if ($value === '') {
            return [null, null];
        }

        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})(?:[ T](.+))?$#', $value, $match) === 1) {
            [$year, $month, $day, $time] = [$match[1], $match[2], $match[3], $match[4] ?? null];
        } elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})(?:\s+(.+))?$#', $value, $match) === 1) {
            [$day, $month, $year, $time] = [$match[1], $match[2], $match[3], $match[4] ?? null];
        } else {
            return [null, null];
        }

        if (! checkdate((int) $month, (int) $day, (int) $year)) {
            return [null, null];
        }

        $date = CarbonImmutable::create((int) $year, (int) $month, (int) $day)->startOfDay();

        try {
            $clock = $time === null ? null : CarbonImmutable::parse($time)->format('H:i');
        } catch (Throwable) {
            $clock = null;
        }

        return [$date, $clock];
    }

    /**
     * `2,620.00` becomes `2620.00`; null when unreadable or empty.
     */
    private function money(string $value): ?string
    {
        $plain = str_replace([',', ' ', 'RM'], '', $value);

        if (preg_match('/^\d+(\.\d{1,2})?$/', $plain) !== 1) {
            return null;
        }

        return (string) BigDecimal::of($plain)->toScale(2);
    }

    private function outcome(string $status, ?CarbonImmutable $arrival, ?CarbonImmutable $departure, ?CarbonImmutable $businessDate): ArrivalImportOutcome
    {
        $status = Str::of($status)->lower()->replace('_', ' ')->squish()->toString();

        $outcome = match (true) {
            Str::contains($status, ['released', 'cancel', 'void', 'no show', 'noshow']) => ArrivalImportOutcome::Skipped,
            Str::contains($status, ['checked out', 'check out']) => ArrivalImportOutcome::PastCheckedOut,
            Str::contains($status, ['checked in', 'in house']) => ArrivalImportOutcome::PastInHouse,
            Str::contains($status, 'hold') => ArrivalImportOutcome::Hold,
            default => ArrivalImportOutcome::Booking,
        };

        if (! $outcome->imports() || $arrival === null || $departure === null || $businessDate === null) {
            return $outcome;
        }

        if ($arrival->gte($businessDate)) {
            return $outcome->isPastStay() ? ArrivalImportOutcome::Booking : $outcome;
        }

        return $departure->gt($businessDate) ? ArrivalImportOutcome::PastInHouse : ArrivalImportOutcome::PastCheckedOut;
    }

    /**
     * Matches by the words of the name, ignoring case, brackets and the word "room": `Deluxe Room (Super King)` is
     * `Deluxe Super King`.
     *
     * @param  Collection<int, RoomType>  $roomTypes
     */
    private function roomType(Collection $roomTypes, string $name): ?RoomType
    {
        $words = $this->words($name);

        return $roomTypes->first(fn (RoomType $roomType): bool => $this->words($roomType->name) === $words);
    }

    /**
     * @return list<string>
     */
    private function words(string $name): array
    {
        $words = array_values(array_diff(preg_split('/[^a-z0-9]+/', Str::lower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [], ['room', 'rooms']));
        sort($words);

        return $words;
    }

    /**
     * By name ignoring case. Empty: the Company source for a company row, else Phone.
     *
     * @param  Collection<int, BookingSource>  $sources
     */
    private function source(Collection $sources, string $name, bool $hasCompany): ?BookingSource
    {
        if ($name === '') {
            return $hasCompany
                ? $sources->firstWhere('type', BookingSourceType::Company)
                : $sources->first(fn (BookingSource $source): bool => Str::lower($source->name) === 'phone');
        }

        $key = Str::lower(Str::squish($name));

        return $sources->first(fn (BookingSource $source): bool => Str::lower($source->name) === $key)
            ?? ($key === 'walk-in' || $key === 'walk in' ? $sources->firstWhere('type', BookingSourceType::WalkIn) : null);
    }

    /**
     * A time is matched to an active departure of the direction by its boat time or its property time. `OWN BOAT`
     * needs no departure, and a value that is not a time (`TBA`) sets no trip.
     *
     * @param  Collection<int, BoatDeparture>|null  $departures  Null when the property has no boats.
     * @return array{0: ?int, 1: ?string} The departure id and a problem.
     */
    private function departure(?Collection $departures, BoatDirection $direction, string $value): array
    {
        if ($value === '' || $departures === null || str_contains($value, 'OWN')) {
            return [null, null];
        }

        $digits = (string) preg_replace('/\D/', '', $value);

        if (strlen($digits) < 3 || strlen($digits) > 4) {
            return [null, null];
        }

        $time = strlen($digits) === 3 ? '0'.$digits : $digits;
        $time = substr($time, 0, 2).':'.substr($time, 2, 2);

        $match = $departures->first(fn (BoatDeparture $departure): bool => $departure->direction === $direction
            && (substr((string) $departure->leaves_at, 0, 5) === $time || substr($direction->propertyTime($departure), 0, 5) === $time));

        if ($match === null) {
            return [null, __('No :direction departure at :time.', ['direction' => $direction->label(), 'time' => $time])];
        }

        return [$match->id, null];
    }
}
