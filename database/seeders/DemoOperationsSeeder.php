<?php

namespace Database\Seeders;

use App\Actions\Boats\CreateBoatDeparture;
use App\Actions\Boats\CreateBoatFare;
use App\Actions\Boats\SetBoatTrip;
use App\Actions\Catalogues\CreateChargeItem;
use App\Actions\CityLedger\CreateCompany;
use App\Actions\Facilities\BookFacility;
use App\Actions\Facilities\CreateFacility;
use App\Actions\Facilities\SaveFacilitySession;
use App\Actions\Reservations\AddUnitAddOn;
use App\Actions\Reservations\CreateBooking;
use App\Actions\Reservations\CreateMarketSegment;
use App\Actions\Reservations\UpdateReservationMarketSegment;
use App\Actions\Reservations\UpdateReservationSalesPerson;
use App\Actions\Transfers\AssignTransfer;
use App\Actions\Transfers\SaveTransferDriver;
use App\Actions\Transfers\SaveTransferRoute;
use App\Actions\Transfers\SaveTransferVehicle;
use App\Actions\Transfers\SetUnitTransfer;
use App\Data\Boats\BoatTripData;
use App\Data\Facilities\FacilityBookingInputData;
use App\Data\Reservations\NewAddOnData;
use App\Data\Reservations\NewBookingData;
use App\Data\Reservations\NewBookingGuestData;
use App\Data\Reservations\NewUnitData;
use App\Data\Transfers\UnitTransferInputData;
use App\Enums\BillingMode;
use App\Enums\BoatDirection;
use App\Enums\BoatMode;
use App\Enums\ChargingUnit;
use App\Enums\CompanyType;
use App\Enums\FacilityBookedBy;
use App\Enums\FacilityRateBasis;
use App\Enums\SellUnit;
use App\Enums\TransactionCategory;
use App\Enums\TransferDirection;
use App\Enums\TransferMode;
use App\Enums\UnitStatus;
use App\Features\Boats;
use App\Features\Facilities;
use App\Features\Transfers;
use App\Models\BoatDeparture;
use App\Models\BookingSource;
use App\Models\ChargeItem;
use App\Models\Company;
use App\Models\Facility;
use App\Models\MarketSegment;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\TransferDriver;
use App\Models\TransferRoute;
use App\Models\TransferVehicle;
use App\Models\UnitTransfer;
use App\Models\User;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Reservations\SalesPeople;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Laravel\Pennant\Feature;
use RuntimeException;

/**
 * The operations Tanini runs, on the two demo properties of the Demo group (Boreno Hotel and Kopi Lane Hostel), on top
 * of the bookings `DemoYearSeeder` makes. Everything goes through the production Actions, as staff would do it:
 *
 * - **Transfers**: airport routes each way with a shared (per person) and a private (per van) price, two vans and three
 *   drivers (one a subcontractor's). About 40% of bookings get a transfer in (with a flight number) and 35% a transfer
 *   out; shared, private or in the package. The next two weeks have a van and a driver.
 * - **Tours and charters** (Facilities, booked by person): each with departure times (sessions), a seat cap and a
 *   private charter price. About a quarter of stays of two nights or more book one; some charter it privately.
 * - **Boats** (Kopi Lane, whose Boats feature is on): jetty departures each way with a speedboat fare, on some bookings.
 * - **Agents and segments**: travel agents and a corporate account, agent-billed bookings, and a market segment and a
 *   sales person on every booking.
 * - **Dorms** (Kopi Lane): whole-dorm buyouts by groups, and groups where each guest pays for their own bed.
 * - **Extras**: breakfast, laundry, towels, padlocks and parking on a share of stays.
 *
 * Run after `DemoYearSeeder`, on a local or staging database only: `php artisan db:seed --class=DemoOperationsSeeder`.
 * A second run adds nothing to a booking that already has its transfer, tour or segment. Fixed random seed.
 */
class DemoOperationsSeeder extends Seeder
{
    /** @var array<string, array{short: string, tours: list<array{name: string, capacity: int, price: string, private: ?string, sessions: list<array{0: string, 1: string}>}>, extras: array<string, int>, boats: bool}> */
    private const array PROPERTIES = [
        'Boreno Hotel' => [
            'short' => 'Boreno',
            'tours' => [
                ['name' => 'Island hopping (Manukan & Sapi)', 'capacity' => 12, 'price' => '150.00', 'private' => '1200.00', 'sessions' => [['08:30', '12:30'], ['13:30', '17:30']]],
                ['name' => 'Klias river cruise & fireflies', 'capacity' => 20, 'price' => '220.00', 'private' => '2400.00', 'sessions' => [['14:00', '20:30']]],
                ['name' => 'Kota Kinabalu heritage walk', 'capacity' => 15, 'price' => '60.00', 'private' => null, 'sessions' => [['09:00', '11:00']]],
            ],
            'extras' => ['PARKING' => 10, 'LAUNDRY_PIECE' => 8, 'MINIBAR_DRINK' => 15],
            'boats' => false,
        ],
        'Kopi Lane Hostel' => [
            'short' => 'Kopi Lane',
            'tours' => [
                ['name' => 'Island hopping day trip', 'capacity' => 12, 'price' => '120.00', 'private' => '1000.00', 'sessions' => [['08:30', '12:30'], ['13:30', '17:30']]],
                ['name' => 'Kinabalu Park day trip', 'capacity' => 10, 'price' => '180.00', 'private' => '1400.00', 'sessions' => [['07:00', '18:00']]],
                ['name' => 'Night market food walk', 'capacity' => 15, 'price' => '45.00', 'private' => null, 'sessions' => [['18:30', '21:00']]],
            ],
            'extras' => ['BREAKFAST' => 30, 'TOWEL' => 20, 'PADLOCK' => 15, 'LAUNDRY_LOAD' => 10],
            'boats' => true,
        ],
    ];

    /** @var list<string> */
    private const array FLIGHTS = ['AK', 'MH', 'OD', 'TR', 'FY', 'CX', 'SQ'];

    /** @var array<string, int> */
    private array $counts = [];

    /** @var array<string, int> */
    private array $refused = [];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoOperationsSeeder writes demo data and never runs in production.');
        }

        foreach (self::PROPERTIES as $name => $setup) {
            $property = Property::query()->where('name', $name)->with('settings')->first();

            if ($property === null) {
                $this->command?->warn("{$name} is not on this database; skipped.");

                continue;
            }

            mt_srand(crc32($name.' operations'));
            $this->counts = [];
            $this->refused = [];
            $owner = $this->owner($property);
            $today = app(CurrentBusinessDate::class)->date($property) ?? CarbonImmutable::today();

            Feature::for($property)->activate(Transfers::class);
            Feature::for($property)->activate(Facilities::class);

            if ($setup['boats']) {
                Feature::for($property)->activate(Boats::class);
            }

            $segments = $this->segments($property, $owner);
            $companies = $this->companies($property, $owner);
            $transfer = $this->transferSetup($property, $owner, $setup['short']);
            $tours = $this->tours($property, $owner, $setup['tours']);
            $departures = $setup['boats'] ? $this->boats($property, $owner) : [];

            $this->agentBookings($property, $owner, $companies, $segments, $today);

            if ($property->roomTypes()->where('sell_unit', SellUnit::Bed->value)->exists()) {
                $this->dormGroups($property, $owner, $segments, $today);
            }

            $this->enrich($property, $owner, $today, $segments, $transfer, $tours, $departures, $setup['extras']);

            $made = collect($this->counts)->map(fn (int $count, string $what): string => "{$what} {$count}")->implode(', ');
            $refused = $this->refused === [] ? 'none' : collect($this->refused)->map(fn (int $count, string $reason): string => "{$reason} {$count}")->implode(', ');
            $this->command?->info("{$name}: {$made}. Refused: {$refused}.");
        }
    }

    /**
     * @return array<string, MarketSegment>
     */
    private function segments(Property $property, User $owner): array
    {
        $segments = [];

        foreach (['Leisure (FIT)', 'Group', 'Corporate', 'Travel agent', 'Online direct'] as $name) {
            $segments[$name] = MarketSegment::query()->where('property_id', $property->id)->where('name', $name)->first()
                ?? app(CreateMarketSegment::class)($property, $owner, $name)
                ?? throw new RuntimeException("Market segment {$name} could not be made.");
        }

        return $segments;
    }

    /**
     * @return list<Company>
     */
    private function companies(Property $property, User $owner): array
    {
        $definitions = [
            ['Borneo Eco Tours', CompanyType::TravelAgent, '20000.00', 'reservations@borneoecotours.test'],
            ['Sabah Adventure Travel', CompanyType::TravelAgent, '15000.00', 'ops@sabahadventure.test'],
            ['Asia Explorer Holidays', CompanyType::TravelAgent, '25000.00', 'groups@asiaexplorer.test'],
            ['Sabah Energy Sdn Bhd', CompanyType::Corporate, '30000.00', 'travel@sabahenergy.test'],
        ];
        $companies = [];

        foreach ($definitions as [$name, $type, $limit, $email]) {
            $companies[] = Company::query()->where('property_id', $property->id)->where('name', $name)->first()
                ?? app(CreateCompany::class)($owner, $property, [
                    'name' => $name,
                    'type' => $type,
                    'contact_email' => $email,
                    'contact_phone' => null,
                    'credit_limit' => $limit,
                    'payment_terms_days' => 30,
                    'direct_bill_enabled' => true,
                ]);
        }

        return $companies;
    }

    /**
     * The airport routes, their prices, the vans and the drivers.
     *
     * @return array{in: TransferRoute, out: TransferRoute, vehicles: list<TransferVehicle>, drivers: list<TransferDriver>}
     */
    private function transferSetup(Property $property, User $owner, string $short): array
    {
        $item = fn (string $name, string $code, string $amount, ChargingUnit $unit): ChargeItem => ChargeItem::query()->where('property_id', $property->id)->where('name', $name)->first()
            ?? app(CreateChargeItem::class)($property, $owner, [
                'name' => $name, 'code' => $code, 'category' => TransactionCategory::Transport->value, 'description' => null,
                'default_amount' => $amount, 'charging_unit' => $unit->value, 'allow_override' => true, 'active' => true, 'tax_ids' => [],
            ]);
        $shared = $item('Airport shuttle (shared)', 'SHUTTLE_SHARED', '35.00', ChargingUnit::PerGuest);
        $private = $item('Airport transfer (private van)', 'TRANSFER_PRIVATE', '120.00', ChargingUnit::PerStay);
        $route = fn (TransferDirection $direction, string $name): TransferRoute => TransferRoute::query()->where('property_id', $property->id)->where('name', $name)->first()
            ?? app(SaveTransferRoute::class)($owner, $property, [
                'direction' => $direction, 'name' => $name, 'place' => 'Kota Kinabalu International Airport (BKI)', 'minutes' => 25,
                'shared_charge_item_id' => $shared->id, 'private_charge_item_id' => $private->id, 'active' => true,
            ]);
        $vehicle = fn (string $name, string $plate, int $seats): TransferVehicle => TransferVehicle::query()->where('property_id', $property->id)->where('name', $name)->first()
            ?? app(SaveTransferVehicle::class)($owner, $property, ['name' => $name, 'plate' => $plate, 'seats' => $seats, 'active' => true]);
        $driver = fn (string $name, string $phone, ?string $subcontractor): TransferDriver => TransferDriver::query()->where('property_id', $property->id)->where('name', $name)->first()
            ?? app(SaveTransferDriver::class)($owner, $property, ['name' => $name, 'phone' => $phone, 'subcontractor' => $subcontractor, 'active' => true]);

        return [
            'in' => $route(TransferDirection::In, "Airport to {$short}"),
            'out' => $route(TransferDirection::Out, "{$short} to airport"),
            'vehicles' => [$vehicle('Van 1', 'SAA 1234 A', 10), $vehicle('Van 2', 'SAB 5678 C', 12)],
            'drivers' => [$driver('Ali Ahmad', '+60 12 830 1122', null), $driver('Ravi Kumar', '+60 13 554 2310', null), $driver('Ah Seng', '+60 16 821 7789', 'Borneo Express Transport')],
        ];
    }

    /**
     * Tours and charters as person-booked facilities, priced each, with their departure times.
     *
     * @param  list<array{name: string, capacity: int, price: string, private: ?string, sessions: list<array{0: string, 1: string}>}>  $definitions
     * @return list<Facility>
     */
    private function tours(Property $property, User $owner, array $definitions): array
    {
        $tours = [];

        foreach ($definitions as $definition) {
            $facility = Facility::query()->where('property_id', $property->id)->where('name', $definition['name'])->first();

            if ($facility === null) {
                $facility = app(CreateFacility::class)($property, $owner, [
                    'name' => $definition['name'],
                    'booked_by' => FacilityBookedBy::Person->value,
                    'capacity' => $definition['capacity'],
                    'default_amount' => $definition['price'],
                    'rate_basis' => FacilityRateBasis::Each->value,
                    'active' => true,
                    'private_amount' => $definition['private'],
                ]);

                foreach ($definition['sessions'] as [$starts, $ends]) {
                    app(SaveFacilitySession::class)($owner, $facility, ['starts_at' => $starts, 'ends_at' => $ends]);
                }
            }

            $tours[] = $facility->load('sessions');
        }

        return $tours;
    }

    /**
     * Jetty departures each way with a speedboat fare (Kopi Lane).
     *
     * @return array{in: list<BoatDeparture>, out: list<BoatDeparture>}
     */
    private function boats(Property $property, User $owner): array
    {
        $fare = ChargeItem::query()->where('property_id', $property->id)->where('name', 'Speedboat fare')->first()
            ?? app(CreateBoatFare::class)($property, $owner, ['name' => 'Speedboat fare', 'code' => 'SPEEDBOAT', 'default_amount' => '40.00', 'allow_override' => false, 'tax_ids' => []]);
        $departure = fn (BoatDirection $direction, string $leaves, string $arrives, string $pickup): BoatDeparture => BoatDeparture::query()
            ->where('property_id', $property->id)->where('direction', $direction->value)->where('leaves_at', $leaves.':00')->first()
            ?? app(CreateBoatDeparture::class)($property, $owner, [
                'direction' => $direction->value, 'leaves_at' => $leaves, 'arrives_at' => $arrives, 'pickup_point' => $pickup,
                'seats' => 20, 'charge_item_id' => $fare->id,
            ]);

        return [
            'in' => [$departure(BoatDirection::In, '09:00', '09:45', 'Jesselton Point jetty'), $departure(BoatDirection::In, '14:00', '14:45', 'Jesselton Point jetty')],
            'out' => [$departure(BoatDirection::Out, '08:00', '08:45', 'Resort jetty'), $departure(BoatDirection::Out, '12:00', '12:45', 'Resort jetty')],
        ];
    }

    /**
     * About 40 bookings over the year from the travel agents and the corporate account, billed to them.
     *
     * @param  list<Company>  $companies
     * @param  array<string, MarketSegment>  $segments
     */
    private function agentBookings(Property $property, User $owner, array $companies, array $segments, CarbonImmutable $today): void
    {
        if (Reservation::query()->where('property_id', $property->id)->whereIn('company_id', array_map(fn (Company $company): int => $company->id, $companies))->count() >= 30) {
            return;
        }

        $source = BookingSource::query()->where('property_id', $property->id)->where('name', 'Company / Travel agent')->first()
            ?? BookingSource::query()->where('property_id', $property->id)->firstOrFail();
        $roomTypes = RoomType::query()->where('property_id', $property->id)->orderBy('position')->get();

        for ($index = 0; $index < 40; $index++) {
            $company = $companies[mt_rand(0, count($companies) - 1)];
            $roomType = $roomTypes[mt_rand(0, $roomTypes->count() - 1)];
            $plan = $this->planFor($property, $roomType);
            $isBed = $roomType->sell_unit === SellUnit::Bed;
            $arrival = $today->addDays(mt_rand(5, 330));
            $nights = mt_rand(2, 4);
            $rooms = $isBed ? 1 : mt_rand(1, 3);

            if ($plan === null) {
                continue;
            }

            $result = app(CreateBooking::class)($owner, $property, new NewBookingData(
                bookingSource: $source,
                units: array_fill(0, $rooms, new NewUnitData(
                    roomTypeId: $roomType->id, ratePlanId: $plan->id, arrivalDate: $arrival, departureDate: $arrival->addDays($nights),
                    adults: $isBed ? 1 : 2, beds: $isBed ? mt_rand(2, 4) : 1,
                )),
                guests: [new NewBookingGuestData(profile: ['full_name' => $this->groupLeader($company->name), 'nationality_code' => ['MY', 'SG', 'CN', 'KR', 'AU'][mt_rand(0, 4)]], unitIndex: 0)],
                billingMode: BillingMode::MasterPays,
                externalReference: strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $company->name) ?? 'AG', 0, 3)).'-'.mt_rand(10000, 99999),
                groupName: $rooms > 1 ? "{$company->name} group" : null,
                companyId: $company->id,
                marketSegmentId: ($company->type === CompanyType::Corporate ? $segments['Corporate'] : $segments['Travel agent'])->id,
            ));

            $this->tally($result->isRefused() ? null : 'agent bookings', $result->refusal);
        }
    }

    /**
     * Whole-dorm buyouts by groups, and groups where each guest pays for their own bed.
     *
     * @param  array<string, MarketSegment>  $segments
     */
    private function dormGroups(Property $property, User $owner, array $segments, CarbonImmutable $today): void
    {
        $dorm = RoomType::query()->where('property_id', $property->id)->where('sell_unit', SellUnit::Bed->value)->orderByDesc('max_adults')->orderBy('id')->first();
        $plan = $dorm === null ? null : $this->planFor($property, $dorm);

        if ($dorm === null || $plan === null || Reservation::query()->where('property_id', $property->id)->whereHas('units', fn ($units) => $units->whereNotNull('whole_room_id'))->exists()) {
            return;
        }

        $source = BookingSource::query()->where('property_id', $property->id)->where('name', 'Email')->firstOrFail();
        $groups = ['Sabah Hiking Club', 'KK Uni Field Trip', 'Borneo Divers Meetup', 'Climb Kinabalu Group', 'Korean Backpackers Club', 'Tip of Borneo Cyclists'];

        foreach ($groups as $index => $group) {
            $arrival = $today->addDays(20 + $index * 45 + mt_rand(0, 10));
            $rooms = Room::query()->where('room_type_id', $dorm->id)->orderBy('number')->get();

            foreach ($rooms as $room) {
                $beds = $room->beds()->whereNull('archived_at')->count();
                $result = app(CreateBooking::class)($owner, $property, new NewBookingData(
                    bookingSource: $source,
                    units: [new NewUnitData(
                        roomTypeId: $dorm->id, ratePlanId: $plan->id, arrivalDate: $arrival, departureDate: $arrival->addDays(2),
                        adults: 1, beds: $beds, wholeRoomId: $room->id,
                    )],
                    guests: [new NewBookingGuestData(profile: ['full_name' => $this->groupLeader($group), 'nationality_code' => 'MY'], unitIndex: 0)],
                    groupName: $group,
                    notes: 'Whole dorm for the group.',
                    marketSegmentId: $segments['Group']->id,
                ));

                if (! $result->isRefused()) {
                    $this->tally('whole-dorm buyouts');

                    break;
                }

                $this->tally(null, $result->refusal);
            }
        }

        foreach (['Down Under Crew', 'Euro Gap Year', 'Seoul Mates', 'Uni Mates from Penang'] as $index => $group) {
            $arrival = $today->addDays(12 + $index * 70 + mt_rand(0, 15));
            $count = mt_rand(3, 4);
            $result = app(CreateBooking::class)($owner, $property, new NewBookingData(
                bookingSource: $source,
                units: [new NewUnitData(roomTypeId: $dorm->id, ratePlanId: $plan->id, arrivalDate: $arrival, departureDate: $arrival->addDays(3), adults: 1, beds: $count)],
                guests: array_map(fn (int $unit): NewBookingGuestData => new NewBookingGuestData(profile: ['full_name' => $this->groupLeader($group).($unit === 0 ? '' : " {$unit}"), 'nationality_code' => ['AU', 'DE', 'KR', 'MY'][$index]], unitIndex: $unit), range(0, $count - 1)),
                billingMode: BillingMode::EachPays,
                groupName: $group,
                notes: 'Friends travelling together; each pays for their own bed.',
                marketSegmentId: $segments['Group']->id,
            ));

            $this->tally($result->isRefused() ? null : 'each-pays groups', $result->refusal);
        }
    }

    /**
     * Segment, sales person, transfers, tours, boats and extras on the coming bookings.
     *
     * @param  array<string, MarketSegment>  $segments
     * @param  array{in: TransferRoute, out: TransferRoute, vehicles: list<TransferVehicle>, drivers: list<TransferDriver>}  $transfer
     * @param  list<Facility>  $tours
     * @param  array{in?: list<BoatDeparture>, out?: list<BoatDeparture>}  $departures
     * @param  array<string, int>  $extras  Charge item code => share of stays (percent).
     */
    private function enrich(Property $property, User $owner, CarbonImmutable $today, array $segments, array $transfer, array $tours, array $departures, array $extras): void
    {
        $salesPeople = array_map(fn ($person): int => $person->id, app(SalesPeople::class)->options($property));
        $items = ChargeItem::query()->where('property_id', $property->id)->with('transactionCode')->whereHas('transactionCode', fn ($code) => $code->whereIn('code', array_keys($extras)))->get()->keyBy(fn (ChargeItem $item): string => $item->transactionCode->code);
        $reservations = Reservation::query()
            ->where('property_id', $property->id)
            ->whereHas('units', fn ($units) => $units->where('arrival_date', '>', $today->toDateString())->where('status', UnitStatus::Confirmed->value))
            ->with(['units' => fn ($units) => $units->orderBy('id'), 'bookingSource', 'units.transfers'])
            ->orderBy('id')
            ->get();

        foreach ($reservations as $reservation) {
            $unit = $reservation->units->first();

            if ($unit === null) {
                continue;
            }

            if ($reservation->market_segment_id === null) {
                $segment = match (true) {
                    $reservation->company_id !== null => $segments['Travel agent'],
                    $reservation->units->count() > 1 || $reservation->group_name !== null => $segments['Group'],
                    $reservation->bookingSource?->name === 'Booking Engine' => $segments['Online direct'],
                    default => $segments['Leisure (FIT)'],
                };
                app(UpdateReservationMarketSegment::class)($owner, $reservation, $segment->id);
                $this->tally('segments');
            }

            if ($reservation->sales_person_id === null && $salesPeople !== [] && mt_rand(1, 100) <= 60) {
                app(UpdateReservationSalesPerson::class)($owner, $reservation, $salesPeople[mt_rand(0, count($salesPeople) - 1)]);
            }

            if ($unit->transfers->isEmpty()) {
                $this->transfers($owner, $unit, $transfer, $today);
            }

            $nights = (int) $unit->arrival_date->diffInDays($unit->departure_date);

            if ($nights >= 2 && mt_rand(1, 100) <= 25 && $tours !== []) {
                $this->bookTour($owner, $reservation, $unit, $tours[mt_rand(0, count($tours) - 1)]);
            }

            if (($departures['in'] ?? []) !== [] && ($departures['out'] ?? []) !== [] && mt_rand(1, 100) <= 12) {
                $result = app(SetBoatTrip::class)($owner, $unit, new BoatTripData(BoatDirection::In, BoatMode::Scheduled, $departures['in'][mt_rand(0, 1)]->id));
                $this->tally($result->isRefused() ? null : 'boat trips', $result->refusal);

                if (! $result->isRefused()) {
                    $out = app(SetBoatTrip::class)($owner, $unit, new BoatTripData(BoatDirection::Out, BoatMode::Scheduled, $departures['out'][mt_rand(0, 1)]->id));
                    $this->tally($out->isRefused() ? null : 'boat trips', $out->refusal);
                }
            }

            foreach ($extras as $code => $share) {
                if (isset($items[$code]) && mt_rand(1, 100) <= $share) {
                    $added = app(AddUnitAddOn::class)($owner, $unit, new NewAddOnData(chargeItemId: $items[$code]->id, quantity: in_array($code, ['LAUNDRY_LOAD', 'LAUNDRY_PIECE', 'MINIBAR_DRINK'], true) ? mt_rand(1, 3) : null));
                    $this->tally($added->isRefused() ? null : 'extras', $added->refusal);
                }
            }
        }
    }

    /**
     * A transfer in for about 40% of stays and out for about 35%, with a flight number; vans and drivers for the next
     * two weeks.
     *
     * @param  array{in: TransferRoute, out: TransferRoute, vehicles: list<TransferVehicle>, drivers: list<TransferDriver>}  $transfer
     */
    private function transfers(User $owner, ReservationUnit $unit, array $transfer, CarbonImmutable $today): void
    {
        foreach ([[TransferDirection::In, $transfer['in'], 40], [TransferDirection::Out, $transfer['out'], 35]] as [$direction, $route, $share]) {
            if (mt_rand(1, 100) > $share) {
                continue;
            }

            $mode = $this->weighted(['shared' => 60, 'private' => 25, 'included' => 15]);
            $hour = $direction === TransferDirection::In ? mt_rand(8, 21) : mt_rand(6, 16);
            $result = app(SetUnitTransfer::class)($owner, $unit, new UnitTransferInputData(
                direction: $direction,
                mode: TransferMode::from($mode),
                routeId: $route->id,
                pickupTime: sprintf('%02d:%02d', $hour, [0, 15, 30, 45][mt_rand(0, 3)]),
                flightNumber: self::FLIGHTS[mt_rand(0, count(self::FLIGHTS) - 1)].' '.mt_rand(100, 6999),
                note: mt_rand(1, 10) === 1 ? 'Child seat needed.' : null,
            ));

            if ($result->isRefused()) {
                $this->tally(null, $result->refusal);

                continue;
            }

            $this->tally('transfers');
            $date = $direction === TransferDirection::In ? $unit->arrival_date : $unit->departure_date;

            if ($date->lte($today->addDays(14))) {
                $row = UnitTransfer::query()->where('reservation_unit_id', $unit->id)->where('direction', $direction->value)->firstOrFail();
                app(AssignTransfer::class)($owner, $row, $transfer['vehicles'][mt_rand(0, 1)]->id, $transfer['drivers'][mt_rand(0, 2)]->id);
            }
        }
    }

    private function bookTour(User $owner, Reservation $reservation, ReservationUnit $unit, Facility $tour): void
    {
        $session = $tour->sessions->count() === 0 ? null : $tour->sessions[mt_rand(0, $tour->sessions->count() - 1)];

        if ($session === null) {
            return;
        }

        $private = $tour->private_amount !== null && mt_rand(1, 100) <= 15;
        $result = app(BookFacility::class)->forReservation($owner, $reservation, new FacilityBookingInputData(
            facilityId: $tour->id,
            bookingDate: $unit->arrival_date->addDay()->toDateString(),
            startsAt: substr($session->starts_at, 0, 5),
            endsAt: substr($session->ends_at, 0, 5),
            quantity: max(1, $unit->adults + $unit->children),
            unitAmount: null,
            notes: $private ? 'Private charter for the family.' : null,
            chargeTo: 'u'.$unit->id,
            private: $private,
        ));

        $this->tally($result->isRefused() ? null : ($private ? 'private charters' : 'tour bookings'), $result->refusal);
    }

    private function planFor(Property $property, RoomType $roomType): ?RatePlan
    {
        return RatePlan::query()->where('property_id', $property->id)->whereHas('roomTypes', fn ($query) => $query->whereKey($roomType->id))->orderBy('id')->first();
    }

    private function groupLeader(string $group): string
    {
        $names = ['Adrian Lee', 'Farah Aziz', 'Kenneth Yap', 'Nadia Omar', 'Joanne Liew', 'Marcus Teo', 'Hana Kim', 'Tom Baker'];

        return $names[crc32($group) % count($names)];
    }

    private function tally(?string $made, ?string $refusal = null): void
    {
        if ($made !== null) {
            $this->counts[$made] = ($this->counts[$made] ?? 0) + 1;

            return;
        }

        if ($refusal !== null) {
            $this->refused[$refusal] = ($this->refused[$refusal] ?? 0) + 1;
        }
    }

    /**
     * @param  array<string, int>  $weights
     */
    private function weighted(array $weights): string
    {
        $pick = mt_rand(1, array_sum($weights));

        foreach ($weights as $value => $weight) {
            $pick -= $weight;

            if ($pick <= 0) {
                return (string) $value;
            }
        }

        return (string) array_key_first($weights);
    }

    private function owner(Property $property): User
    {
        setPermissionsTeamId($property->id);
        $role = Role::query()->where('property_id', $property->id)->where('name', 'Owner')->firstOrFail();

        return User::query()->whereHas('roles', fn ($query) => $query->whereKey($role->id))->first()
            ?? throw new RuntimeException("{$property->name} has no owner.");
    }
}
