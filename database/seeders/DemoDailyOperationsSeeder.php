<?php

namespace Database\Seeders;

use App\Actions\Boats\SaveBoat;
use App\Actions\Boats\SaveBoatCrewMember;
use App\Actions\Boats\UpdateBoatDeparture;
use App\Actions\Concierge\SaveGuestContacts;
use App\Actions\Facilities\AssignFacilitySlotCrew;
use App\Actions\Facilities\BookFacility;
use App\Actions\Facilities\SaveFacilityBoat;
use App\Actions\Facilities\SaveFacilityGuide;
use App\Actions\Reservations\SaveReservationRemarks;
use App\Actions\Transfers\AssignTransfer;
use App\Actions\Transfers\AssignTransferRun;
use App\Actions\Transfers\SetUnitTransfer;
use App\Data\Facilities\FacilityBookingInputData;
use App\Data\Transfers\UnitTransferInputData;
use App\Enums\FacilityBookedBy;
use App\Enums\FacilityBookingStatus;
use App\Enums\RemarkKind;
use App\Enums\TransferDirection;
use App\Enums\TransferMode;
use App\Enums\UnitStatus;
use App\Models\Boat;
use App\Models\BoatCrewMember;
use App\Models\BoatDeparture;
use App\Models\Facility;
use App\Models\FacilityBoat;
use App\Models\FacilityBooking;
use App\Models\FacilityGuide;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationRemark;
use App\Models\ReservationUnit;
use App\Models\Role;
use App\Models\TransferDriver;
use App\Models\TransferRoute;
use App\Models\TransferVehicle;
use App\Models\UnitTransfer;
use App\Models\User;
use App\Services\NightAudit\CurrentBusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * The day-to-day operations of the two demo lodges (Boreno Hotel and Kopi Lane Hostel) from the business date to
 * `DAYS` days ahead, on top of `DemoOperationsSeeder`. Every write goes through the production Actions, and a second
 * run changes nothing it already set:
 *
 * - **Emergency phones** for the transfer manifest (duty manager and emergency).
 * - **Island boats and crew** (Boats module) and each departure's default boat and crew, where the lodge has departures.
 * - **Activity bookings** that fill every session to 40-90% of its places with guests staying that night.
 * - **Transfers** for about 60% of the arrivals and half of the departures that have none (shared or in the package).
 * - **Guides and boats** of the activities, and a guide (and, for boat trips, a boat) on every booked session.
 * - **Dietary remarks** on about a third of the bookings arriving or doing an activity in the window.
 * - **Bags** on every transfer in the window.
 * - **Van runs**: shared and in-package transfers move to the run times of their direction (in 10:00, 14:00, 18:00; out 07:00, 11:00,
 *   15:00) and share a van and driver; private transfers keep their time on the second van. Every third day one transfer is
 *   left with no van, so the Vans board shows its "No van yet" card.
 *
 * Run it with `php artisan db:seed --class=DemoDailyOperationsSeeder` whenever the demo's business date moves on.
 */
class DemoDailyOperationsSeeder extends Seeder
{
    /** How many days after the business date get operations. */
    private const int DAYS = 14;

    /** @var array<string, array{duty: string, emergency: string, guides: list<array{0: string, 1: string}>, boats: list<array{0: string, 1: int}>}> */
    private const array PROPERTIES = [
        'Boreno Hotel' => [
            'duty' => '+60 88 251 300',
            'emergency' => '+60 12 830 4400',
            'guides' => [['Jamil Sulaiman', '+60 13 870 2211'], ['Nurul Aina', '+60 19 812 3300'], ['Daniel Lo', '+60 16 833 9012']],
            'boats' => [['Hornbill', 12], ['Kingfisher', 10]],
        ],
        'Kopi Lane Hostel' => [
            'duty' => '+60 88 212 880',
            'emergency' => '+60 11 2345 6789',
            'guides' => [['Rosli Ahmad', '+60 14 556 1200'], ['Melati Chong', '+60 17 820 4567'], ['Jason Wong', '+60 12 845 3321']],
            'boats' => [['Proboscis', 12], ['Otter', 8]],
        ],
    ];

    /** @var list<string> */
    private const array DIETARY = [
        'Vegetarian',
        'Vegan, no honey',
        'Halal only',
        'No pork, no lard',
        'Peanut allergy (severe)',
        'Shellfish allergy',
        'Gluten free',
        'Lactose intolerant',
        'No beef',
        'Diabetic, low sugar',
    ];

    /** @var array<string, list<string>> */
    private const array RUN_TIMES = ['in' => ['10:00', '14:00', '18:00'], 'out' => ['07:00', '11:00', '15:00']];

    /** @var array<string, int> */
    private array $counts = [];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoDailyOperationsSeeder writes demo data and never runs in production.');
        }

        foreach (self::PROPERTIES as $name => $setup) {
            $property = Property::query()->where('name', $name)->with('settings')->first();

            if ($property === null) {
                $this->command?->warn("{$name} is not on this database; skipped.");

                continue;
            }

            mt_srand(crc32($name.' daily operations'));
            $this->counts = [];
            $owner = $this->owner($property);
            $from = app(CurrentBusinessDate::class)->date($property) ?? CarbonImmutable::today();
            $to = $from->addDays(self::DAYS);

            $this->phones($property, $owner, $setup);
            $this->islandBoats($property, $owner);
            $this->activities($property, $owner, $from, $to);
            $this->newTransfers($property, $owner, $from, $to);
            $this->crews($property, $owner, $setup, $from, $to);
            $this->dietary($property, $owner, $from, $to);
            $this->transfers($property, $owner, $from, $to);

            $made = collect($this->counts)->map(fn (int $count, string $what): string => "{$what} {$count}")->implode(', ');
            $this->command?->info("{$name} ({$from->toDateString()} to {$to->toDateString()}): {$made}.");
        }
    }

    /**
     * @param  array{duty: string, emergency: string}  $setup
     */
    private function phones(Property $property, User $owner, array $setup): void
    {
        app(SaveGuestContacts::class)($owner, $property, [
            'duty_manager_phone' => $setup['duty'],
            'emergency_phone' => $setup['emergency'],
            'front_desk_open_24h' => $property->settings->front_desk_open_24h ?? true,
        ]);
    }

    /**
     * For a lodge with island boat departures (the Boats module): two speedboats and four crew members, and each
     * departure's default boat and crew (in charge plus one assistant). The Boat Trips board starts every date's sailing
     * from these defaults. A departure that already has a default boat keeps it.
     */
    private function islandBoats(Property $property, User $owner): void
    {
        $departures = BoatDeparture::query()->where('property_id', $property->id)->orderBy('leaves_at')->get();

        if ($departures->isEmpty()) {
            return;
        }

        $boats = array_map(fn (array $boat): Boat => Boat::query()->where('property_id', $property->id)->where('name', $boat[0])->first()
            ?? app(SaveBoat::class)($owner, $property, ['name' => $boat[0], 'registration' => $boat[1], 'seats' => 20, 'active' => true]), [['Kopi Express 1', 'SBH 2271'], ['Kopi Express 2', 'SBH 2288']]);
        $crew = array_map(fn (array $member): BoatCrewMember => BoatCrewMember::query()->where('property_id', $property->id)->where('name', $member[0])->first()
            ?? app(SaveBoatCrewMember::class)($owner, $property, ['name' => $member[0], 'phone' => $member[1], 'active' => true]), [
                ['Awang Jaafar', '+60 13 881 2040'], ['Daus Ismail', '+60 19 830 7712'], ['Faizal Omar', '+60 14 862 5519'], ['Hafiz Rahman', '+60 17 845 0034'],
            ]);

        foreach ($departures as $index => $departure) {
            if ($departure->default_boat_id !== null) {
                continue;
            }

            $pair = $index % 2;
            $result = app(UpdateBoatDeparture::class)($owner, $departure, [
                'default_boat_id' => $boats[$pair]->id,
                'in_charge_id' => $crew[$pair * 2]->id,
                'assistant_ids' => [$crew[$pair * 2 + 1]->id],
            ]);
            $this->count($result->isRefused() ? "departure refused ({$result->refusal})" : 'departures with boat and crew');
        }
    }

    /**
     * Fills every activity session in the window to 40-90% of its places with the guests staying that night who have
     * not booked that activity on that date, charged to their room.
     */
    private function activities(Property $property, User $owner, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $facilities = Facility::query()
            ->where('property_id', $property->id)
            ->where('booked_by', FacilityBookedBy::Person)
            ->where('active', true)
            ->with('sessions')
            ->ordered()
            ->get();

        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $staying = ReservationUnit::query()
                ->where('property_id', $property->id)
                ->whereIn('status', [UnitStatus::Confirmed, UnitStatus::CheckedIn])
                ->where('arrival_date', '<=', $date->toDateString())
                ->where('departure_date', '>', $date->toDateString())
                ->with('reservation')
                ->orderBy('id')
                ->get()
                ->sortBy(fn (ReservationUnit $unit): int => crc32($unit->id.' '.$date->toDateString()))
                ->values();

            foreach ($facilities as $facility) {
                foreach ($facility->sessions as $session) {
                    $booked = (int) FacilityBooking::query()
                        ->where('facility_id', $facility->id)
                        ->whereDate('booking_date', $date->toDateString())
                        ->where('starts_at', $session->starts_at)
                        ->whereIn('status', [FacilityBookingStatus::Tentative, FacilityBookingStatus::Confirmed])
                        ->sum('quantity');
                    $target = (int) round($facility->capacity * mt_rand(40, 90) / 100);

                    foreach ($staying as $unit) {
                        $pax = max(1, $unit->adults + $unit->children);

                        if ($booked + $pax > $target) {
                            continue;
                        }

                        $alreadyBooked = FacilityBooking::query()
                            ->where('facility_id', $facility->id)
                            ->where('reservation_id', $unit->reservation_id)
                            ->whereDate('booking_date', $date->toDateString())
                            ->where('status', '<>', FacilityBookingStatus::Cancelled)
                            ->exists();

                        if ($alreadyBooked) {
                            continue;
                        }

                        $result = app(BookFacility::class)->forReservation($owner, $unit->reservation, new FacilityBookingInputData(
                            facilityId: $facility->id,
                            bookingDate: $date->toDateString(),
                            startsAt: substr($session->starts_at, 0, 5),
                            endsAt: substr($session->ends_at, 0, 5),
                            quantity: $pax,
                            unitAmount: null,
                            notes: null,
                            chargeTo: 'u'.$unit->id,
                            private: false,
                        ));

                        if ($result->isRefused()) {
                            $this->count("activity refused ({$result->refusal})");

                            continue;
                        }

                        $booked += $pax;
                        $this->count('activity bookings');
                    }
                }
            }
        }
    }

    /**
     * A transfer for about 60% of the stays arriving, and half of those leaving, in the window that have none in that
     * direction: shared, or in the package for one in four, with a flight number.
     */
    private function newTransfers(Property $property, User $owner, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $routes = [
            'in' => TransferRoute::query()->where('property_id', $property->id)->where('direction', TransferDirection::In)->where('active', true)->orderBy('id')->first(),
            'out' => TransferRoute::query()->where('property_id', $property->id)->where('direction', TransferDirection::Out)->where('active', true)->orderBy('id')->first(),
        ];
        $units = ReservationUnit::query()
            ->where('property_id', $property->id)
            ->whereIn('status', [UnitStatus::Confirmed, UnitStatus::CheckedIn])
            ->where(fn ($query) => $query
                ->whereBetween('arrival_date', [$from->toDateString(), $to->toDateString()])
                ->orWhereBetween('departure_date', [$from->toDateString(), $to->toDateString()]))
            ->with('transfers')
            ->orderBy('id')
            ->get();

        foreach ($units as $unit) {
            foreach ([TransferDirection::In, TransferDirection::Out] as $direction) {
                $date = $direction === TransferDirection::In ? $unit->arrival_date : $unit->departure_date;
                $route = $routes[$direction->value];
                $share = $direction === TransferDirection::In ? 60 : 50;

                if ($route === null || ! $date->betweenIncluded($from, $to) || $unit->transfers->contains('direction', $direction)
                    || ($direction === TransferDirection::In && $unit->status !== UnitStatus::Confirmed)
                    || crc32($direction->value.' transfer '.$unit->id) % 100 >= $share) {
                    continue;
                }

                $times = self::RUN_TIMES[$direction->value];
                $result = app(SetUnitTransfer::class)($owner, $unit, new UnitTransferInputData(
                    direction: $direction,
                    mode: crc32('mode '.$unit->id) % 4 === 0 ? TransferMode::Included : TransferMode::Shared,
                    routeId: $route->id,
                    pickupTime: $times[crc32('time '.$unit->id.$direction->value) % count($times)],
                    flightNumber: ['AK', 'MH', 'OD', 'TR'][crc32('airline '.$unit->id) % 4].' '.(100 + crc32('flight '.$unit->id.$direction->value) % 6800),
                ));
                $this->count($result->isRefused() ? "transfer refused ({$result->refusal})" : 'new transfers');
            }
        }
    }

    /**
     * Adds the guides and boats, then puts a guide on every booked activity session in the window, and a boat on the
     * sessions of a boat trip (a name with island, river or cruise).
     *
     * @param  array{guides: list<array{0: string, 1: string}>, boats: list<array{0: string, 1: int}>}  $setup
     */
    private function crews(Property $property, User $owner, array $setup, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $guides = array_map(fn (array $guide): FacilityGuide => FacilityGuide::query()->where('property_id', $property->id)->where('name', $guide[0])->first()
            ?? app(SaveFacilityGuide::class)($owner, $property, ['name' => $guide[0], 'phone' => $guide[1], 'active' => true]), $setup['guides']);
        $boats = array_map(fn (array $boat): FacilityBoat => FacilityBoat::query()->where('property_id', $property->id)->where('name', $boat[0])->first()
            ?? app(SaveFacilityBoat::class)($owner, $property, ['name' => $boat[0], 'seats' => $boat[1], 'active' => true]), $setup['boats']);

        $slots = FacilityBooking::query()
            ->where('property_id', $property->id)
            ->whereBetween('booking_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('status', [FacilityBookingStatus::Tentative, FacilityBookingStatus::Confirmed])
            ->with('facility')
            ->orderBy('booking_date')
            ->orderBy('starts_at')
            ->get()
            ->unique(fn (FacilityBooking $booking): string => $booking->facility_id.'|'.$booking->booking_date->toDateString().'|'.$booking->starts_at.'|'.$booking->ends_at)
            ->values();

        foreach ($slots as $index => $booking) {
            $byBoat = preg_match('/island|river|cruise/i', $booking->facility->name) === 1;
            $refusal = app(AssignFacilitySlotCrew::class)(
                $owner,
                $booking->facility,
                $booking->booking_date,
                substr($booking->starts_at, 0, 5),
                substr($booking->ends_at, 0, 5),
                $guides[$index % count($guides)]->id,
                $byBoat ? $boats[$index % count($boats)]->id : null,
            );
            $this->count($refusal === null ? 'session crews' : "crew refused ({$refusal})");
        }
    }

    /**
     * A dietary remark on about a third of the bookings arriving or doing an activity in the window, keeping their other
     * remarks. A booking that already has one keeps it.
     */
    private function dietary(Property $property, User $owner, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $reservations = Reservation::query()
            ->where('property_id', $property->id)
            ->where(fn ($query) => $query
                ->whereHas('units', fn ($units) => $units->whereBetween('arrival_date', [$from->toDateString(), $to->toDateString()])->whereIn('status', [UnitStatus::Confirmed, UnitStatus::CheckedIn]))
                ->orWhereIn('id', FacilityBooking::query()->where('property_id', $property->id)->whereBetween('booking_date', [$from->toDateString(), $to->toDateString()])->whereNotNull('reservation_id')->select('reservation_id')))
            ->with('remarks')
            ->orderBy('id')
            ->get();

        foreach ($reservations as $reservation) {
            $remarks = $reservation->remarks->mapWithKeys(fn (ReservationRemark $remark): array => [$remark->kind->value => $remark->body])->all();

            if (isset($remarks[RemarkKind::Dietary->value]) || crc32('dietary '.$reservation->id) % 3 !== 0) {
                continue;
            }

            $remarks[RemarkKind::Dietary->value] = self::DIETARY[crc32('diet '.$reservation->id) % count(self::DIETARY)];
            app(SaveReservationRemarks::class)($owner, $reservation, $remarks);
            $this->count('dietary remarks');
        }
    }

    /**
     * Bags on every open transfer in the window, then the van runs of each date and direction.
     */
    private function transfers(Property $property, User $owner, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $vehicles = TransferVehicle::query()->where('property_id', $property->id)->where('active', true)->orderBy('name')->get();
        $drivers = TransferDriver::query()->where('property_id', $property->id)->where('active', true)->orderBy('name')->get();

        if ($vehicles->count() < 2 || $drivers->isEmpty()) {
            $this->command?->warn("{$property->name} needs two vans and a driver; run DemoOperationsSeeder first.");

            return;
        }

        $transfers = UnitTransfer::query()
            ->where('property_id', $property->id)
            ->whereHas('reservationUnit', fn ($units) => $units->whereIn('status', [UnitStatus::Confirmed, UnitStatus::CheckedIn]))
            ->with('reservationUnit')
            ->orderBy('id')
            ->get()
            ->filter(function (UnitTransfer $transfer) use ($from, $to): bool {
                $date = $transfer->direction === TransferDirection::In ? $transfer->reservationUnit->arrival_date : $transfer->reservationUnit->departure_date;

                return $date->betweenIncluded($from, $to);
            })
            ->values();

        foreach ($transfers as $transfer) {
            $this->bags($owner, $transfer);
        }

        $days = $transfers->groupBy(fn (UnitTransfer $transfer): string => ($transfer->direction === TransferDirection::In ? $transfer->reservationUnit->arrival_date : $transfer->reservationUnit->departure_date)->toDateString());

        foreach ($days as $date => $dayTransfers) {
            $this->runs($owner, $property, $dayTransfers, $vehicles, $drivers, $from->diffInDays(CarbonImmutable::parse((string) $date)) % 3 === 0);
        }
    }

    private function bags(User $owner, UnitTransfer $transfer): void
    {
        if ($transfer->bags !== null) {
            return;
        }

        $unit = $transfer->reservationUnit;
        $result = app(SetUnitTransfer::class)($owner, $unit, new UnitTransferInputData(
            direction: $transfer->direction,
            mode: $transfer->mode,
            routeId: $transfer->transfer_route_id,
            pickupTime: substr($transfer->pickup_time, 0, 5),
            flightNumber: $transfer->flight_number,
            note: $transfer->note,
            bags: max(1, $unit->adults + mt_rand(-1, 2)),
        ));
        $this->count($result->isRefused() ? "bags refused ({$result->refusal})" : 'bags');
    }

    /**
     * Shared transfers of the date move to the nearest run time of their direction and share a van and driver; private
     * ones keep their time on the second van with the last driver. With `$leaveOne` (every third day), the last shared
     * transfer of the date gets no van.
     *
     * @param  Collection<int, UnitTransfer>  $transfers
     * @param  Collection<int, TransferVehicle>  $vehicles
     * @param  Collection<int, TransferDriver>  $drivers
     */
    private function runs(User $owner, Property $property, Collection $transfers, Collection $vehicles, Collection $drivers, bool $leaveOne): void
    {
        $shared = $transfers->filter(fn (UnitTransfer $transfer): bool => $transfer->mode !== TransferMode::Private)->values();
        $unassigned = $leaveOne && $shared->count() > 1 ? $shared->slice(-1) : collect();

        foreach ($unassigned as $transfer) {
            app(AssignTransfer::class)($owner, $transfer, null, null);
            $this->count('left with no van');
        }

        $groups = $shared->slice(0, $shared->count() - $unassigned->count())->groupBy(function (UnitTransfer $transfer): string {
            $times = self::RUN_TIMES[$transfer->direction->value];
            $minutes = (int) substr($transfer->pickup_time, 0, 2) * 60 + (int) substr($transfer->pickup_time, 3, 2);
            $nearest = collect($times)->sortBy(fn (string $time): int => abs((int) substr($time, 0, 2) * 60 - $minutes))->first();

            return $transfer->direction->value.'|'.$transfer->transfer_route_id.'|'.$nearest;
        });

        foreach ($groups->values() as $index => $group) {
            [, , $time] = explode('|', (string) $groups->keys()[$index]);
            $result = app(AssignTransferRun::class)(
                $owner,
                $property,
                array_values($group->map(fn (UnitTransfer $transfer): int => $transfer->id)->all()),
                $vehicles[$index % 2]->id,
                $drivers[$index % max(1, $drivers->count() - 1)]->id,
                $time,
            );
            $this->count($result->isRefused() ? "run refused ({$result->refusal})" : 'van runs');
        }

        foreach ($transfers->filter(fn (UnitTransfer $transfer): bool => $transfer->mode === TransferMode::Private) as $transfer) {
            $result = app(AssignTransfer::class)($owner, $transfer, $vehicles[1]->id, $drivers->last()->id);
            $this->count($result->isRefused() ? "private refused ({$result->refusal})" : 'private transfers');
        }
    }

    private function owner(Property $property): User
    {
        setPermissionsTeamId($property->id);
        $role = Role::query()->where('property_id', $property->id)->where('name', 'Owner')->firstOrFail();

        return User::query()->whereHas('roles', fn ($query) => $query->whereKey($role->id))->first()
            ?? throw new RuntimeException("{$property->name} has no owner.");
    }

    private function count(string $what): void
    {
        $this->counts[$what] = ($this->counts[$what] ?? 0) + 1;
    }
}
