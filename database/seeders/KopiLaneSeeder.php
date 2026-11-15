<?php

namespace Database\Seeders;

use App\Actions\Catalogues\CreateChargeItem;
use App\Actions\Catalogues\CreateDiscount;
use App\Actions\Catalogues\UpdateRoomRevenueTaxes;
use App\Actions\Catalogues\UpdateTax;
use App\Actions\FrontDesk\CancelUnit;
use App\Actions\FrontDesk\MarkNoShow;
use App\Actions\FrontDesk\UnitStayResult;
use App\Actions\Guests\CreateGuest;
use App\Actions\Housekeeping\SetRoomRemark;
use App\Actions\Housekeeping\UpdateRoomStatus;
use App\Actions\NightAudit\OpenBusinessDate;
use App\Actions\Property\GenerateDocumentPrefix;
use App\Actions\Property\IssuePropertyCode;
use App\Actions\Property\SeedPropertyDefaults;
use App\Actions\Rates\CreateRatePlan;
use App\Actions\Rates\SaveRates;
use App\Actions\Rates\UpdateRatePlan;
use App\Actions\Reservations\CreateBooking;
use App\Actions\Rooms\CreateRoomBlock;
use App\Actions\Rooms\CreateRoomType;
use App\Actions\Rooms\GenerateBeds;
use App\Actions\Rooms\GenerateRooms;
use App\Actions\Rooms\RoomBlockRefused;
use App\Actions\Staff\AssignOwnerRole;
use App\Actions\Staff\AssignStaffRole;
use App\Data\Catalogues\TaxInputData;
use App\Data\Reservations\NewBookingData;
use App\Data\Reservations\NewBookingGuestData;
use App\Data\Reservations\NewUnitData;
use App\Data\Reservations\UnitPickData;
use App\Data\Rooms\RoomBlockInputData;
use App\Enums\BillingMode;
use App\Enums\ChargingUnit;
use App\Enums\DefaultRoleEnum;
use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Enums\HousekeepingStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Enums\PricingBasis;
use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Enums\RoomBlockReason;
use App\Enums\SellUnit;
use App\Enums\TransactionCategory;
use App\Models\Bed;
use App\Models\BookingSource;
use App\Models\ChargeItem;
use App\Models\Guest;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tax;
use App\Models\User;
use App\Services\Property\PropertyToday;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\SeedsThroughActions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Seeds Kopi Lane Hostel for the demo environment: a backpacker hostel in Kota Kinabalu that sells beds. Four dorm
 * rooms with 26 beds (two 8-bed mixed dorms, a 6-bed female dorm and a 4-bed dorm) and four private doubles. A live
 * property with an open business date, its catalogues, a per-bed plan for the dorms, Room Only for the private rooms,
 * backpackers and about 15 reservations dated relative to the business date: solo beds, a group of friends on four
 * beds, a club that takes a whole dorm, arrivals, in-house beds, a no-show, a cancellation and a blocked bed. Beds are
 * assigned by `auto_assign_rooms`. Room charges are not seeded: they post at the night audit, and the seeder runs
 * none. Every step uses the production Actions. The Owner signs in as `kopilane@demo.estatell.com`, and every staff
 * account uses `DEMO_PASSWORD`. See docs/01-platform/06-demo-properties.md.
 */
class KopiLaneSeeder extends Seeder
{
    use SeedsThroughActions;

    private const string NAME = 'Kopi Lane Hostel';

    /**
     * Room types: name, sell unit, room numbers, beds per room (dorms only), group, occupancy, weekday and weekend
     * nightly price (per bed for a dorm).
     *
     * @var list<array{name: string, description: string, sell_unit: SellUnit, numbers: list<string>, beds: int, group: string, base: int, adults: int, children: int, weekday: string, weekend: string}>
     */
    private const array ROOM_TYPES = [
        ['name' => '8-Bed Mixed Dorm', 'description' => 'Bunk beds with curtains, a reading light, a power socket and a locker.', 'sell_unit' => SellUnit::Bed, 'numbers' => ['D1', 'D2'], 'beds' => 8, 'group' => 'Dorms', 'base' => 1, 'adults' => 1, 'children' => 0, 'weekday' => '38.00', 'weekend' => '45.00'],
        ['name' => '6-Bed Female Dorm', 'description' => 'Female-only dorm with its own bathroom.', 'sell_unit' => SellUnit::Bed, 'numbers' => ['D3'], 'beds' => 6, 'group' => 'Dorms', 'base' => 1, 'adults' => 1, 'children' => 0, 'weekday' => '45.00', 'weekend' => '52.00'],
        ['name' => '4-Bed Dorm', 'description' => 'A quieter dorm with four single beds.', 'sell_unit' => SellUnit::Bed, 'numbers' => ['D4'], 'beds' => 4, 'group' => 'Dorms', 'base' => 1, 'adults' => 1, 'children' => 0, 'weekday' => '50.00', 'weekend' => '58.00'],
        ['name' => 'Private Double', 'description' => 'Double bed and a fan, shared bathroom.', 'sell_unit' => SellUnit::Room, 'numbers' => ['101', '102', '103', '104'], 'beds' => 0, 'group' => 'Private Rooms', 'base' => 2, 'adults' => 2, 'children' => 0, 'weekday' => '110.00', 'weekend' => '128.00'],
    ];

    /**
     * Guests: name, nationality (ISO code), and the city shown on the profile.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const array GUESTS = [
        ['Lukas Becker', 'DE', 'Munich'], ['Emma Johansson', 'SE', 'Gothenburg'], ['Tom Walker', 'GB', 'Leeds'],
        ['Chloe Dubois', 'FR', 'Marseille'], ['Kenji Sato', 'JP', 'Osaka'], ['Ji-woo Park', 'KR', 'Busan'],
        ['Liam O\'Brien', 'IE', 'Cork'], ['Sofia Rossi', 'IT', 'Bologna'], ['Noah Visser', 'NL', 'Utrecht'],
        ['Mia Schneider', 'DE', 'Hamburg'], ['Jack Taylor', 'AU', 'Brisbane'], ['Olivia Wilson', 'NZ', 'Wellington'],
        ['Hannah Müller', 'CH', 'Zurich'], ['Lucas Silva', 'BR', 'Curitiba'], ['Aisha Rahman', 'MY', 'Kuala Lumpur'],
        ['Farah Izzati', 'MY', 'Kota Kinabalu'], ['Daniel Ong', 'SG', 'Singapore'], ['Putri Lestari', 'ID', 'Bandung'],
        ['Mark Reyes', 'PH', 'Cebu'], ['Tran Thi Mai', 'VN', 'Hanoi'], ['Ethan Clarke', 'CA', 'Vancouver'],
        ['Isabella Garcia', 'ES', 'Valencia'], ['Matteo Bianchi', 'IT', 'Turin'], ['Nur Aqilah', 'MY', 'Ranau'],
        ['Hafiz Jaafar', 'MY', 'Penampang'], ['Siti Hajar', 'MY', 'Tuaran'], ['Amir Hakim', 'MY', 'Papar'],
        ['Zara Ahmed', 'GB', 'Manchester'], ['Ryan Cooper', 'US', 'Denver'], ['Anna Kowalska', 'PL', 'Krakow'],
    ];

    /**
     * Everything runs in one transaction, so a refused step leaves nothing behind.
     */
    public function run(): void
    {
        if (Property::query()->where('name', self::NAME)->exists()) {
            $this->command?->warn(self::NAME.' already exists. Nothing was seeded.');

            return;
        }

        DB::transaction(fn () => $this->seed());
    }

    private function seed(): void
    {
        $property = $this->createProperty();
        $owner = $this->createStaff($property);
        $today = CarbonImmutable::parse(app(PropertyToday::class)->date($property)->toDateString());

        app(OpenBusinessDate::class)($property, $today, $owner);

        $this->seedTaxes($property, $owner);
        $items = $this->seedChargeItems($property, $owner);
        $this->seedDiscount($property, $owner);
        $roomTypes = $this->seedRooms($property, $owner);
        $plans = $this->seedRatePlans($property, $owner, $roomTypes, $today);
        $this->blockBed($property, $owner, $today);
        $guests = $this->seedGuests($property, $owner);
        $this->seedReservations($property, $owner, $roomTypes, $plans, $items, $guests, $today);
        $this->seedHousekeeping($property, $owner);

        $this->command?->info(self::NAME.' seeded: '.Reservation::query()->where('property_id', $property->id)->count().' reservations, '.$property->beds()->count().' beds.');
    }

    private function createProperty(): Property
    {
        $property = (new Property)->forceFill([
            'code' => app(IssuePropertyCode::class)(),
            'name' => self::NAME,
            'type' => PropertyType::Hostel,
            'status' => PropertyStatus::Onboarding,
            'address_line1' => '7 Lorong Dewan',
            'city' => 'Kota Kinabalu',
            'state_code' => 'Sabah',
            'postal_code' => '88000',
            'country_code' => 'MY',
            'contact_email' => 'hello@kopilane.test',
            'contact_phone' => '+6088251234',
            'onboarding_started_at' => now()->subDays(14),
        ]);
        $property->save();

        app(GenerateDocumentPrefix::class)($property);
        app(SeedPropertyDefaults::class)($property, PropertyType::Hostel, 'MY');
        $property->forceFill(['status' => PropertyStatus::Live, 'went_live_at' => now()])->save();

        return $property->refresh();
    }

    /**
     * Create the four staff accounts and return the Owner.
     */
    private function createStaff(Property $property): User
    {
        $names = [
            DefaultRoleEnum::Owner->value => 'Adrian Wong',
            DefaultRoleEnum::Manager->value => 'Melissa Tan',
            DefaultRoleEnum::FrontDesk->value => 'Iqbal Front Desk',
            DefaultRoleEnum::Housekeeping->value => 'Juliana Housekeeping',
        ];
        $users = [];

        foreach ([DefaultRoleEnum::Owner, DefaultRoleEnum::Manager, DefaultRoleEnum::FrontDesk, DefaultRoleEnum::Housekeeping] as $role) {
            $user = new User(['name' => $names[$role->value], 'email' => DemoSeeder::loginEmail('kopilane', $role), 'password' => DemoSeeder::password()]);
            $user->property_id = $property->id;
            $user->email_verified_at = now();
            $user->save();
            $users[$role->value] = $user;
        }

        $owner = $users[DefaultRoleEnum::Owner->value];
        app(AssignOwnerRole::class)($property, $owner);

        foreach ([DefaultRoleEnum::Manager, DefaultRoleEnum::FrontDesk, DefaultRoleEnum::Housekeeping] as $role) {
            $roleModel = Role::query()->where('property_id', $property->id)->where('default_role', $role)->firstOrFail();

            if (! app(AssignStaffRole::class)($owner, $users[$role->value], $roleModel)) {
                throw new RuntimeException("Could not assign the {$role->value} role.");
            }
        }

        return $owner;
    }

    /**
     * Enable Tourism Tax on room revenue. A small hostel is under the SST threshold, so SST stays off.
     */
    private function seedTaxes(Property $property, User $owner): void
    {
        $tourism = Tax::query()->where('property_id', $property->id)->where('code', 'TTX')->firstOrFail();

        app(UpdateTax::class)($owner, $tourism, TaxInputData::fromModel($tourism, enabled: true));
        app(UpdateRoomRevenueTaxes::class)($property, $owner, [$tourism->id]);
    }

    /**
     * @return array<string, ChargeItem> Keyed by code.
     */
    private function seedChargeItems(Property $property, User $owner): array
    {
        $definitions = [
            ['Breakfast (toast and kopi)', 'BREAKFAST', TransactionCategory::FoodAndBeverage, '12.00', ChargingUnit::PerGuestNight],
            ['Locker padlock', 'PADLOCK', TransactionCategory::Fee, '10.00', ChargingUnit::PerItem],
            ['Towel rental', 'TOWEL', TransactionCategory::Fee, '5.00', ChargingUnit::PerStay],
            ['Laundry (per load)', 'LAUNDRY_LOAD', TransactionCategory::Fee, '15.00', ChargingUnit::PerItem],
            ['Mount Kinabalu shuttle', 'KINABALU_SHUTTLE', TransactionCategory::Fee, '45.00', ChargingUnit::PerItem],
            ['Island hopping tour', 'ISLAND_TOUR', TransactionCategory::Other, '120.00', ChargingUnit::PerItem],
        ];

        $items = [];

        foreach ($definitions as [$name, $code, $category, $amount, $unit]) {
            $items[$code] = app(CreateChargeItem::class)($property, $owner, [
                'name' => $name,
                'code' => $code,
                'category' => $category->value,
                'description' => null,
                'default_amount' => $amount,
                'charging_unit' => $unit->value,
                'allow_override' => false,
                'active' => true,
                'tax_ids' => [],
            ]);
        }

        return $items;
    }

    private function seedDiscount(Property $property, User $owner): void
    {
        app(CreateDiscount::class)($property, $owner, [
            'name' => 'Student card',
            'code' => 'STUDENT',
            'type' => DiscountType::Percent->value,
            'value' => '10.00',
            'allow_override' => false,
            'scope' => DiscountScope::RoomOnly->value,
            'active' => true,
            'transaction_code_ids' => [],
        ]);
    }

    /**
     * Create the room types, their rooms, and the beds of each dorm room. Beds are labelled by room letter: D1 gets
     * A1 to A8, D2 B1 to B8, D3 C1 to C6 and D4 D1 to D4.
     *
     * @return array<string, RoomType> Keyed by name.
     */
    private function seedRooms(Property $property, User $owner): array
    {
        $roomTypes = [];

        foreach (self::ROOM_TYPES as $definition) {
            $roomType = app(CreateRoomType::class)($property, $owner, [
                'name' => $definition['name'],
                'description' => $definition['description'],
                'sell_unit' => $definition['sell_unit']->value,
                'max_adults' => $definition['adults'],
                'max_children' => $definition['children'],
                'base_occupancy' => $definition['base'],
                'smoking_allowed' => false,
                'pets_allowed' => false,
            ]);

            $plan = array_map(fn (string $number): array => ['number' => $number, 'group' => $definition['group']], $definition['numbers']);
            $result = app(GenerateRooms::class)($property, $owner, $roomType, null, $plan);

            if ($result['conflicts'] !== []) {
                throw new RuntimeException('Room numbers already taken: '.implode(', ', $result['conflicts']));
            }

            if ($definition['sell_unit'] === SellUnit::Bed) {
                $roomIds = array_values(array_map(intval(...), Room::query()->where('room_type_id', $roomType->id)->pluck('id')->all()));
                $expected = $definition['beds'] * count($roomIds);

                if (app(GenerateBeds::class)($property, $owner, $roomIds, $definition['beds']) !== $expected) {
                    throw new RuntimeException("Could not create the beds of {$definition['name']}.");
                }
            }

            $roomTypes[$definition['name']] = $roomType;
        }

        return $roomTypes;
    }

    /**
     * Rename the starter plan to Room Only for the private rooms, add Dorm Bed (priced per bed) for the dorms, and
     * price the next 200 days. Friday and Saturday nights cost more.
     *
     * @param  array<string, RoomType>  $roomTypes
     * @return array<string, RatePlan> Keyed by name.
     */
    private function seedRatePlans(Property $property, User $owner, array $roomTypes, CarbonImmutable $today): array
    {
        $dormNames = ['8-Bed Mixed Dorm', '6-Bed Female Dorm', '4-Bed Dorm'];

        $roomOnly = RatePlan::query()->where('property_id', $property->id)->orderBy('position')->firstOrFail();
        app(UpdateRatePlan::class)($owner, $roomOnly, ['name' => 'Room Only', 'description' => 'Private room, no meals.', 'extra_adult_amount' => '30.00', 'extra_child_amount' => '15.00']);

        $dormBed = app(CreateRatePlan::class)($property, $owner, [
            'name' => 'Dorm Bed',
            'description' => 'One bed in a dorm, with a locker and free coffee.',
            'pricing_basis' => PricingBasis::PerBed->value,
        ], array_map(fn (string $name): int => $roomTypes[$name]->id, $dormNames));

        $plans = ['Room Only' => $roomOnly->refresh(), 'Dorm Bed' => $dormBed];

        foreach ([['Room Only', ['Private Double']], ['Dorm Bed', $dormNames]] as [$planName, $roomTypeNames]) {
            $rates = [];

            foreach (self::ROOM_TYPES as $definition) {
                if (! in_array($definition['name'], $roomTypeNames, true)) {
                    continue;
                }

                $days = [];

                foreach (range(1, 7) as $weekday) {
                    $amount = in_array($weekday, [5, 6], true) ? $definition['weekend'] : $definition['weekday'];
                    $days[$weekday] = ['amount' => (string) BigDecimal::of($amount)->toScale(2), 'occupancy' => []];
                }

                $rates[] = ['room_type_id' => $roomTypes[$definition['name']]->id, 'days' => $days];
            }

            if (app(SaveRates::class)($owner, $plans[$planName], $today->toDateString(), $today->addDays(200)->toDateString(), $rates) === false) {
                throw new RuntimeException("Could not save rates for {$planName}.");
            }
        }

        return $plans;
    }

    /**
     * Block bed A8 in dorm D1 for three nights from today: its bunk ladder is broken. The room stays on sale.
     */
    private function blockBed(Property $property, User $owner, CarbonImmutable $today): void
    {
        $room = Room::query()->where('property_id', $property->id)->where('number', 'D1')->firstOrFail();
        $bed = Bed::query()->where('room_id', $room->id)->where('label', 'A8')->firstOrFail();

        $result = app(CreateRoomBlock::class)($owner, $room, $bed, new RoomBlockInputData(
            reason: RoomBlockReason::Maintenance,
            startsOn: $today->toDateString(),
            endsOn: $today->addDays(2)->toDateString(),
            notes: 'Bunk ladder is broken. New ladder arrives on Friday.',
        ));

        if ($result instanceof RoomBlockRefused) {
            throw new RuntimeException('The bed block was refused.');
        }
    }

    /**
     * @return list<Guest>
     */
    private function seedGuests(Property $property, User $owner): array
    {
        $guests = [];

        foreach (self::GUESTS as $index => [$name, $nationality, $city]) {
            $slug = strtolower(preg_replace('/[^a-z]+/i', '.', $name) ?? 'guest');
            $guests[] = app(CreateGuest::class)($owner, $property, [
                'full_name' => $name,
                'email' => trim($slug, '.').'@guests.test',
                'phone' => '+6011'.str_pad((string) (6100000 + $index * 173), 7, '0', STR_PAD_LEFT),
                'nationality_code' => $nationality,
                'country_code' => $nationality,
                'city' => $city,
            ]);
        }

        return $guests;
    }

    /**
     * About 15 reservations from the business date on: solo beds, friends on four beds (each pays), a hiking club that
     * takes all of dorm D2 (one bill), private rooms, arrivals today, beds in house, a no-show and a cancellation.
     *
     * @param  array<string, RoomType>  $roomTypes
     * @param  array<string, RatePlan>  $plans
     * @param  array<string, ChargeItem>  $items
     * @param  list<Guest>  $guests
     */
    private function seedReservations(Property $property, User $owner, array $roomTypes, array $plans, array $items, array $guests, CarbonImmutable $today): void
    {
        /**
         * Book one line of `$units` units (beds for a dorm), one guest per unit from `$guestIndexes`. The first guest
         * is the booker. With `room`, the units take that dorm's beds in order; otherwise `auto_assign_rooms` assigns them.
         *
         * @param  list<int>  $guestIndexes
         * @param  array{source?: string, billing?: BillingMode, group?: string, notes?: string, room?: string}  $options
         */
        $book = function (string $roomTypeName, int $startOffset, int $nights, array $guestIndexes, array $options = []) use ($property, $owner, $roomTypes, $plans, $guests, $today): Reservation {
            $roomType = $roomTypes[$roomTypeName];
            $isDorm = $roomType->sell_unit === SellUnit::Bed;

            $result = app(CreateBooking::class)($owner, $property, new NewBookingData(
                bookingSource: $this->bookingSource($property, $options['source'] ?? null),
                units: [new NewUnitData(
                    roomTypeId: $roomType->id,
                    ratePlanId: $plans[$isDorm ? 'Dorm Bed' : 'Room Only']->id,
                    arrivalDate: $today->addDays($startOffset),
                    departureDate: $today->addDays($startOffset + $nights),
                    adults: $isDorm ? 1 : 2,
                    beds: $isDorm ? count($guestIndexes) : 1,
                )],
                guests: array_map(fn (int $guestIndex, int $unitIndex): NewBookingGuestData => new NewBookingGuestData(guestId: $guests[$guestIndex]->id, unitIndex: $unitIndex), $guestIndexes, array_keys($guestIndexes)),
                picks: isset($options['room']) ? $this->bedPicks($property, $options['room'], count($guestIndexes)) : [],
                billingMode: $options['billing'] ?? BillingMode::MasterPays,
                groupName: $options['group'] ?? null,
                notes: $options['notes'] ?? null,
            ));

            if ($result->isRefused()) {
                throw new RuntimeException("Booking for {$guests[$guestIndexes[0]]->full_name} was refused: {$result->refusal}.");
            }

            return $result->reservation;
        };

        // Future bookings.
        $friends = $book('8-Bed Mixed Dorm', 3, 3, [10, 11, 20, 27], ['billing' => BillingMode::EachPays, 'group' => 'Down Under Crew', 'notes' => 'Four friends, each pays for their own bed. Want beds next to each other.']);
        $book('8-Bed Mixed Dorm', 2, 4, [13], ['source' => 'Email']);
        $book('6-Bed Female Dorm', 4, 2, [12]);
        $book('4-Bed Dorm', 5, 3, [21, 22], ['notes' => 'Couple travelling together.']);
        $book('Private Double', 6, 2, [8], ['source' => 'Phone']);
        $book('8-Bed Mixed Dorm', 10, 2, [23, 24, 25, 26, 14, 15, 17, 18], ['room' => 'D2', 'group' => 'Sabah Hiking Club', 'notes' => 'The whole of one 8-bed dorm before the Kinabalu climb. One bill to the club.']);
        $book('6-Bed Female Dorm', 14, 5, [29]);

        // Arrivals today, left Confirmed to try check-in.
        $book('8-Bed Mixed Dorm', 0, 2, [0, 1], ['notes' => 'Arriving on the evening bus from Sandakan.']);
        $book('6-Bed Female Dorm', 0, 3, [3]);
        $book('Private Double', 0, 2, [16]);

        // In house.
        $kenji = $book('8-Bed Mixed Dorm', 0, 4, [4], ['source' => 'Walk-in']);
        $tom = $book('4-Bed Dorm', 0, 2, [2]);
        $sofia = $book('6-Bed Female Dorm', 0, 3, [7], ['notes' => 'Climbing Mount Kinabalu on day 2. Leaves her big bag in storage.']);

        $noShow = $book('8-Bed Mixed Dorm', 0, 1, [6]);
        $this->forEachUnit($noShow, fn (ReservationUnit $unit): UnitStayResult => app(MarkNoShow::class)($owner, $unit), 'The no-show');

        $cancelled = $book('4-Bed Dorm', 8, 2, [9]);
        $this->forEachUnit($cancelled, fn (ReservationUnit $unit): UnitStayResult => app(CancelUnit::class)($owner, $unit), 'The cancellation');

        $methods = PaymentMethod::query()->where('property_id', $property->id)->where('active', true)->get()->keyBy(fn (PaymentMethod $method): string => $method->type->value);

        $this->advance($owner, $friends, $friends->units()->orderBy('id')->firstOrFail(), $methods[PaymentMethodType::Card->value], '50.00');

        $kenjiUnit = $this->checkIn($owner, $kenji)->first();
        $this->charge($owner, $kenjiUnit, $items['PADLOCK']);
        $this->charge($owner, $kenjiUnit, $items['TOWEL']);
        $this->pay($owner, $kenji, $this->unitFolio($kenjiUnit), $methods[PaymentMethodType::Cash->value], PaymentPurpose::Advance, '160.00');

        $tomUnit = $this->checkIn($owner, $tom)->first();
        $this->charge($owner, $tomUnit, $items['LAUNDRY_LOAD']);

        $sofiaUnit = $this->checkIn($owner, $sofia)->first();
        $this->charge($owner, $sofiaUnit, $items['KINABALU_SHUTTLE']);
        $this->charge($owner, $sofiaUnit, $items['BREAKFAST'], 2);
    }

    /**
     * Room statuses for the board: a dirty dorm and private room, an inspected room, and a remark.
     */
    private function seedHousekeeping(Property $property, User $owner): void
    {
        $rooms = Room::query()->where('property_id', $property->id)->get()->keyBy('number');

        foreach (['D2' => HousekeepingStatus::Dirty, '103' => HousekeepingStatus::Dirty, '104' => HousekeepingStatus::Inspected] as $number => $status) {
            $refusal = app(UpdateRoomStatus::class)($owner, $rooms[$number], $status);

            if ($refusal !== null) {
                throw new RuntimeException("Room {$number} could not be set to {$status->value}.");
            }
        }

        app(SetRoomRemark::class)($owner, $rooms['D2'], 'Change all bunk sheets and restock the lockers with padlocks.');
    }

    /**
     * Pick the first `$count` active beds of a dorm room, one per unit.
     *
     * @return list<UnitPickData>
     */
    private function bedPicks(Property $property, string $roomNumber, int $count): array
    {
        $room = Room::query()->where('property_id', $property->id)->where('number', $roomNumber)->firstOrFail();
        $bedIds = Bed::query()->where('room_id', $room->id)->active()->ordered()->limit($count)->pluck('id')->all();

        return array_map(fn (int $bedId, int $unitIndex): UnitPickData => new UnitPickData(unitIndex: $unitIndex, bedId: $bedId), array_map(intval(...), $bedIds), array_keys($bedIds));
    }

    private int $sourceCursor = 0;

    /**
     * A booking source of the property by name. Without a name the default Direct sources take turns (Phone, WhatsApp,
     * Email), so the sample list shows more than one source.
     */
    private function bookingSource(Property $property, ?string $name = null): BookingSource
    {
        $name ??= ['Phone', 'WhatsApp', 'Email'][$this->sourceCursor++ % 3];

        return BookingSource::query()->where('property_id', $property->id)->where('name', $name)->firstOrFail();
    }
}
