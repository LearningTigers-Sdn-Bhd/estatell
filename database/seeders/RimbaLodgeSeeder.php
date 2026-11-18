<?php

namespace Database\Seeders;

use App\Actions\BookingEngine\SaveBookingWebsiteGeneral;
use App\Actions\BookingEngine\SetPropertyAmenity;
use App\Actions\BookingEngine\SetRatePlanBookableOnline;
use App\Actions\BookingEngine\SetUpBookingSite;
use App\Actions\Catalogues\CreateChargeItem;
use App\Actions\Catalogues\UpdateRoomRevenueTaxes;
use App\Actions\Catalogues\UpdateTax;
use App\Actions\CityLedger\CreateCompany;
use App\Actions\CityLedger\RecordArPayment;
use App\Actions\CityLedger\SettleToCityLedger;
use App\Actions\Folio\CloseFolio;
use App\Actions\Folio\CreateFolio;
use App\Actions\Folio\TransferLines;
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
use App\Actions\Property\UpdatePropertyProfile;
use App\Actions\Rates\CreateRatePlan;
use App\Actions\Rates\SavePackagePricing;
use App\Actions\Rates\SaveRatePlanInclusions;
use App\Actions\Rates\SaveRatePlanStayDiscounts;
use App\Actions\Rates\SaveRates;
use App\Actions\Rates\UpdateRatePlan;
use App\Actions\Reservations\CreateBooking;
use App\Actions\Rooms\CreateRoomType;
use App\Actions\Rooms\GenerateBeds;
use App\Actions\Rooms\GenerateRooms;
use App\Actions\Rooms\SetRoomTypeAmenity;
use App\Actions\Staff\AssignOwnerRole;
use App\Actions\Staff\AssignStaffRole;
use App\Data\Catalogues\TaxInputData;
use App\Data\CityLedger\NewArPaymentData;
use App\Data\Reservations\NewBookingData;
use App\Data\Reservations\NewBookingGuestData;
use App\Data\Reservations\NewUnitData;
use App\Enums\AmenityType;
use App\Enums\BillingMode;
use App\Enums\BookableOnlineResult;
use App\Enums\ChargingUnit;
use App\Enums\CompanyType;
use App\Enums\DefaultRoleEnum;
use App\Enums\FolioStatus;
use App\Enums\HousekeepingStatus;
use App\Enums\InclusionEnd;
use App\Enums\InclusionStart;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Enums\PricingBasis;
use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Enums\SellUnit;
use App\Enums\StayDiscountType;
use App\Enums\TransactionCategory;
use App\Features\BedRoomTypes;
use App\Features\BookingEngine;
use App\Models\Amenity;
use App\Models\ArInvoice;
use App\Models\BookingSource;
use App\Models\ChargeItem;
use App\Models\Company;
use App\Models\Guest;
use App\Models\MealPeriod;
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
use App\Services\BookingEngine\MapsPlace;
use App\Services\Folio\FolioBalance;
use App\Services\Property\PropertyToday;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\SeedsThroughActions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use RuntimeException;

/**
 * Seeds Rimba Kinabatangan Lodge in production, for sales pitches: a rainforest lodge on the Kinabatangan River in
 * Sabah. Chalets and a family longhouse room sold by the room, and a researcher dorm sold by the bed. A live property
 * with an open business date, SST and Tourism Tax, Room Only and two packages (2D1N and 3D2N, meals and river cruises
 * included, priced per guest), a per-bed plan for researchers, guests and about 16 reservations dated relative to the
 * business date: package couples and families, a tour group from a travel agent, researchers billed to an NGO, arrivals,
 * stays in house, a no-show, a cancellation and an agent stay settled to the city ledger. The booking engine is on in
 * Microsite mode with the About text, location and amenities set, but not published: it needs photos first. Room
 * charges are not seeded: they post at the night audit, and the seeder runs none. Every step uses the production
 * Actions. Staff sign in as `owner@rimbalodge.com` (and manager, frontdesk, housekeeping) with `BORENO_SEED_PASSWORD`
 * (the internal pitch password, shared with Boreno). It is never part of the demo. See
 * docs/deployment/production_environment.md.
 */
class RimbaLodgeSeeder extends Seeder
{
    use SeedsThroughActions;

    private const string NAME = 'Rimba Kinabatangan Lodge';

    /**
     * Room types: name, sell unit, room numbers, beds per room (bed types only), group, occupancy, weekday and weekend
     * nightly price (per bed for a bed type), and the 2D1N and 3D2N package prices per guest (null: no packages).
     *
     * @var list<array{name: string, description: string, sell_unit: SellUnit, numbers: list<string>, beds: int, group: string, base: int, adults: int, children: int, weekday: string, weekend: string, package_1: string|null, package_2: string|null}>
     */
    private const array ROOM_TYPES = [
        ['name' => 'River View Chalet', 'description' => 'A wooden chalet on stilts with a balcony over the Kinabatangan River. Queen bed and its own bathroom.', 'sell_unit' => SellUnit::Room, 'numbers' => ['R1', 'R2', 'R3', 'R4', 'R5', 'R6'], 'beds' => 0, 'group' => 'Riverside', 'base' => 2, 'adults' => 2, 'children' => 1, 'weekday' => '380.00', 'weekend' => '420.00', 'package_1' => '450.00', 'package_2' => '780.00'],
        ['name' => 'Jungle Chalet', 'description' => 'A chalet at the forest edge with a terrace. Two single beds or a queen, and its own bathroom.', 'sell_unit' => SellUnit::Room, 'numbers' => ['J1', 'J2', 'J3', 'J4', 'J5', 'J6'], 'beds' => 0, 'group' => 'Forest Edge', 'base' => 2, 'adults' => 2, 'children' => 1, 'weekday' => '300.00', 'weekend' => '340.00', 'package_1' => '390.00', 'package_2' => '680.00'],
        ['name' => 'Family Longhouse Room', 'description' => 'A large room in the longhouse with a queen bed and two singles, a sofa bed and a shared veranda.', 'sell_unit' => SellUnit::Room, 'numbers' => ['L1', 'L2'], 'beds' => 0, 'group' => 'Longhouse', 'base' => 2, 'adults' => 4, 'children' => 2, 'weekday' => '480.00', 'weekend' => '540.00', 'package_1' => '380.00', 'package_2' => '680.00'],
        ['name' => 'Researcher Dorm', 'description' => 'Six single beds with lockers at the field station, for researchers and volunteers. Shared bathroom.', 'sell_unit' => SellUnit::Bed, 'numbers' => ['F1'], 'beds' => 6, 'group' => 'Field Station', 'base' => 1, 'adults' => 1, 'children' => 0, 'weekday' => '70.00', 'weekend' => '70.00', 'package_1' => null, 'package_2' => null],
    ];

    /**
     * Property amenities, by name, from the platform amenity list.
     *
     * @var list<string>
     */
    private const array PROPERTY_AMENITIES = [
        'Free Wi-Fi in public areas', 'Non-smoking rooms', 'Family rooms', 'Surau (prayer room)', 'Free parking',
        'Garden', 'Terrace', 'Outdoor furniture', 'Waterfront', 'Restaurant', 'Bar', 'Breakfast buffet', 'Halal food',
        'Packed lunches', 'Drinking water', 'Tour desk', 'Baggage storage', 'Laundry service', 'Daily housekeeping',
        'Shuttle service (paid)', 'Shared lounge', 'Library', 'Hiking', 'Walking tours', 'Local culture tours',
        'Canoeing', 'Fishing', '24-hour security', 'First aid kit', 'Fire extinguishers', 'Smoke alarms',
        'No single-use plastics', 'Refillable water stations', 'Waste reduction programme', 'Water conservation programme',
        'Energy-efficient lighting',
    ];

    /**
     * Room amenities, by name, for every room type, then the ones only some room types have.
     *
     * @var array<string, list<string>>
     */
    private const array ROOM_AMENITIES = [
        '*' => ['Mosquito net', 'Ceiling fan', 'Linen', 'Towels', 'Shower'],
        'River View Chalet' => ['Private bathroom', 'Air conditioning', 'Balcony', 'River view', 'Electric kettle', 'Free toiletries'],
        'Jungle Chalet' => ['Private bathroom', 'Air conditioning', 'Terrace', 'Garden view', 'Electric kettle', 'Free toiletries'],
        'Family Longhouse Room' => ['Private bathroom', 'Air conditioning', 'Sofa bed', 'Seating area', 'Electric kettle', 'Free toiletries'],
        'Researcher Dorm' => ['Shared bathroom', 'Lockers', 'Reading light', 'Socket near the bed', 'Desk'],
    ];

    private const string ABOUT = 'Rimba Kinabatangan Lodge sits on the banks of the Kinabatangan River, a boat ride from Sandakan. Wake up to hornbills and proboscis monkeys, and look for orangutans and pygmy elephants on river cruises at dawn and dusk. Stay in a river view or jungle chalet, or bring the family to the longhouse. Our 2D1N and 3D2N packages include every meal, river cruises and a guided night walk. Researchers and volunteers stay at our field station dorm.';

    /**
     * Guests: name, nationality (ISO code), and the city shown on the profile.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const array GUESTS = [
        ['James Whitfield', 'GB', 'Bristol'], ['Sarah Whitfield', 'GB', 'Bristol'], ['Jan de Vries', 'NL', 'Rotterdam'],
        ['Femke de Vries', 'NL', 'Rotterdam'], ['Pieter Bakker', 'NL', 'Amsterdam'], ['Anouk Jansen', 'NL', 'Leiden'],
        ['Maarten Smit', 'NL', 'Delft'], ['Lotte Meijer', 'NL', 'Haarlem'], ['Felix Wagner', 'DE', 'Berlin'],
        ['Lena Wagner', 'DE', 'Berlin'], ['Hiroshi Tanaka', 'JP', 'Kyoto'], ['Yuki Tanaka', 'JP', 'Kyoto'],
        ['Chen Wei', 'CN', 'Shanghai'], ['Li Na', 'CN', 'Shanghai'], ['Oliver Harris', 'AU', 'Perth'],
        ['Chloe Harris', 'AU', 'Perth'], ['Ahmad Faizal', 'MY', 'Kuala Lumpur'], ['Nur Hidayah', 'MY', 'Kuala Lumpur'],
        ['Liew Kah Seng', 'MY', 'Sandakan'], ['Grace Liew', 'MY', 'Sandakan'], ['Dr Rahmah Ismail', 'MY', 'Kota Kinabalu'],
        ['Marcus Lee', 'SG', 'Singapore'], ['Emily Carter', 'US', 'Portland'], ['Tomás Ortega', 'ES', 'Seville'],
        ['Sophie Laurent', 'FR', 'Lyon'], ['Daniel Svensson', 'SE', 'Uppsala'], ['Aiko Mori', 'JP', 'Sapporo'],
        ['Benjamin Hughes', 'GB', 'Edinburgh'], ['Isabel Moreno', 'ES', 'Madrid'], ['Nathan Brooks', 'NZ', 'Christchurch'],
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

        $password = $this->staffPassword();

        DB::transaction(fn () => $this->seed($password));
    }

    private function seed(string $password): void
    {
        $property = $this->createProperty();
        $owner = $this->createStaff($property, $password);
        $today = CarbonImmutable::parse(app(PropertyToday::class)->date($property)->toDateString());

        app(OpenBusinessDate::class)($property, $today, $owner);

        $this->seedTaxes($property, $owner);
        $items = $this->seedChargeItems($property, $owner);
        $roomTypes = $this->seedRooms($property, $owner);
        $plans = $this->seedRatePlans($property, $owner, $roomTypes, $items, $today);
        $guests = $this->seedGuests($property, $owner);
        $companies = $this->seedCompanies($property, $owner);
        $this->seedReservations($property, $owner, $roomTypes, $plans, $items, $guests, $companies, $today);
        $this->seedHousekeeping($property, $owner);
        $this->seedAmenities($property, $owner, $roomTypes);
        $this->seedBookingSite($property, $owner);

        $this->command?->info(self::NAME.' seeded: '.Reservation::query()->where('property_id', $property->id)->count().' reservations, '.Room::query()->where('property_id', $property->id)->count().' rooms.');
    }

    private function createProperty(): Property
    {
        $property = (new Property)->forceFill([
            'code' => app(IssuePropertyCode::class)(),
            'name' => self::NAME,
            'type' => PropertyType::Resort,
            'status' => PropertyStatus::Onboarding,
            'address_line1' => 'Lot 5, Kampung Bilit',
            'city' => 'Kinabatangan',
            'state_code' => 'Sabah',
            'postal_code' => '90200',
            'country_code' => 'MY',
            'contact_email' => 'hello@rimbalodge.test',
            'contact_phone' => '+6089565123',
            'onboarding_started_at' => now()->subDays(14),
        ]);
        $property->save();

        app(GenerateDocumentPrefix::class)($property);
        app(SeedPropertyDefaults::class)($property, PropertyType::Resort, 'MY');
        Feature::for($property)->activate(BedRoomTypes::class);
        $property->forceFill(['status' => PropertyStatus::Live, 'went_live_at' => now()])->save();

        return $property->refresh();
    }

    /**
     * Create the four staff accounts and return the Owner.
     */
    private function createStaff(Property $property, string $password): User
    {
        $names = [
            DefaultRoleEnum::Owner->value => 'Justin Gimpoton',
            DefaultRoleEnum::Manager->value => 'Hasnah Abdullah',
            DefaultRoleEnum::FrontDesk->value => 'Roslan Front Desk',
            DefaultRoleEnum::Housekeeping->value => 'Maria Housekeeping',
        ];
        $users = [];

        foreach ([DefaultRoleEnum::Owner, DefaultRoleEnum::Manager, DefaultRoleEnum::FrontDesk, DefaultRoleEnum::Housekeeping] as $role) {
            $user = new User(['name' => $names[$role->value], 'email' => str_replace('_', '', $role->value).'@rimbalodge.com', 'password' => $password]);
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

    private function staffPassword(): string
    {
        $password = config('seeding.boreno_password');

        if (! is_string($password) || strlen($password) < 8) {
            throw new RuntimeException('Set BORENO_SEED_PASSWORD to at least 8 characters before seeding Rimba Kinabatangan Lodge.');
        }

        return $password;
    }

    /**
     * Enable SST and Tourism Tax and link both to the room revenue codes.
     */
    private function seedTaxes(Property $property, User $owner): void
    {
        $sst = Tax::query()->where('property_id', $property->id)->where('code', 'SST')->firstOrFail();
        $tourism = Tax::query()->where('property_id', $property->id)->where('code', 'TTX')->firstOrFail();

        app(UpdateTax::class)($owner, $sst, TaxInputData::fromModel($sst, enabled: true));
        app(UpdateTax::class)($owner, $tourism, TaxInputData::fromModel($tourism, enabled: true));
        app(UpdateRoomRevenueTaxes::class)($property, $owner, [$sst->id, $tourism->id]);
    }

    /**
     * @return array<string, ChargeItem> Keyed by code.
     */
    private function seedChargeItems(Property $property, User $owner): array
    {
        $definitions = [
            ['Breakfast', 'BREAKFAST', TransactionCategory::FoodAndBeverage, '35.00', ChargingUnit::PerGuestNight],
            ['Lunch', 'LUNCH', TransactionCategory::FoodAndBeverage, '45.00', ChargingUnit::PerGuestNight],
            ['Dinner', 'DINNER', TransactionCategory::FoodAndBeverage, '60.00', ChargingUnit::PerGuestNight],
            ['Packed lunch', 'PACKED_LUNCH', TransactionCategory::FoodAndBeverage, '25.00', ChargingUnit::PerItem],
            ['River cruise', 'RIVER_CRUISE', TransactionCategory::Other, '90.00', ChargingUnit::PerGuest],
            ['Guided night walk', 'NIGHT_WALK', TransactionCategory::Other, '60.00', ChargingUnit::PerGuest],
            ['Oxbow lake trek', 'OXBOW_TREK', TransactionCategory::Other, '120.00', ChargingUnit::PerGuest],
            ['Binocular rental', 'BINOCULARS', TransactionCategory::Fee, '20.00', ChargingUnit::PerItem],
            ['Jetty transfer from Sandakan', 'JETTY_TRANSFER', TransactionCategory::Fee, '150.00', ChargingUnit::PerGuest],
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

    /**
     * Create the room types, their rooms, and the beds of the researcher dorm.
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
     * Rename the starter plan to Room Only for the chalets and longhouse, add the two packages (meals, river cruises and
     * a night walk included, priced per guest) and Researcher Bed (per bed, cheaper from two weeks), and price the next
     * 200 days. Friday and Saturday nights cost more in the chalets.
     *
     * @param  array<string, RoomType>  $roomTypes
     * @param  array<string, ChargeItem>  $items
     * @return array<string, RatePlan> Keyed by name.
     */
    private function seedRatePlans(Property $property, User $owner, array $roomTypes, array $items, CarbonImmutable $today): array
    {
        $roomNames = ['River View Chalet', 'Jungle Chalet', 'Family Longhouse Room'];
        $roomIds = array_map(fn (string $name): int => $roomTypes[$name]->id, $roomNames);

        $roomOnly = RatePlan::query()->where('property_id', $property->id)->orderBy('position')->firstOrFail();
        app(UpdateRatePlan::class)($owner, $roomOnly, ['name' => 'Room Only', 'description' => 'The room, no meals or activities.', 'extra_adult_amount' => '80.00', 'extra_child_amount' => '40.00']);

        $researcher = app(CreateRatePlan::class)($property, $owner, [
            'name' => 'Researcher Bed',
            'description' => 'One bed at the field station dorm, for researchers and volunteers.',
            'pricing_basis' => PricingBasis::PerBed->value,
        ], [$roomTypes['Researcher Dorm']->id]);

        $plans = ['Room Only' => $roomOnly->refresh(), 'Researcher Bed' => $researcher];

        foreach ([['Room Only', $roomNames], ['Researcher Bed', ['Researcher Dorm']]] as [$planName, $roomTypeNames]) {
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

        if (! app(SaveRatePlanStayDiscounts::class)($owner, $researcher, [['min_nights' => 14, 'discount_type' => StayDiscountType::Percent->value, 'value' => '20.00', 'from_night' => 1]])) {
            throw new RuntimeException('Could not save the long-stay discount of Researcher Bed.');
        }

        $meals = MealPeriod::query()->where('property_id', $property->id)->pluck('id', 'name');
        $meal = fn (string $code, string $mealName, string $amount, InclusionStart $start, InclusionEnd $end): array => $this->inclusion($items[$code], $meals[$mealName] ?? null, ChargingUnit::PerGuestNight, 1, $amount, $start, $end);
        $breakfast = $meal('BREAKFAST', 'Breakfast', '35.00', InclusionStart::DayAfterArrival, InclusionEnd::DepartureDay);
        $lunch = $meal('LUNCH', 'Lunch', '45.00', InclusionStart::DayAfterArrival, InclusionEnd::NightBeforeDeparture);
        $dinner = $meal('DINNER', 'Dinner', '60.00', InclusionStart::ArrivalDay, InclusionEnd::NightBeforeDeparture);
        $activity = fn (string $code, int $quantity, string $amount): array => $this->inclusion($items[$code], null, ChargingUnit::PerGuest, $quantity, $amount, InclusionStart::ArrivalDay, InclusionEnd::DepartureDay);

        $packages = [
            ['2D1N Rainforest Package', 1, 'package_1', 'One night with dinner, breakfast, a dusk and a dawn river cruise and a guided night walk.', [$breakfast, $dinner, $activity('RIVER_CRUISE', 2, '50.00'), $activity('NIGHT_WALK', 1, '50.00')]],
            ['3D2N Wildlife Package', 2, 'package_2', 'Two nights with every meal, four river cruises, a night walk and the oxbow lake trek.', [$breakfast, $lunch, $dinner, $activity('RIVER_CRUISE', 4, '50.00'), $activity('NIGHT_WALK', 1, '50.00'), $activity('OXBOW_TREK', 1, '100.00')]],
        ];

        foreach ($packages as [$name, $nights, $priceKey, $description, $inclusions]) {
            $package = app(CreateRatePlan::class)($property, $owner, [
                'name' => $name,
                'description' => $description,
                'pricing_basis' => PricingBasis::Package->value,
                'fixed_nights' => $nights,
                'package_unit' => 'per_guest',
            ], $roomIds);

            if (! app(SaveRatePlanInclusions::class)($owner, $package, $inclusions)) {
                throw new RuntimeException("Could not save the inclusions of {$name}.");
            }

            $prices = [];

            foreach (self::ROOM_TYPES as $definition) {
                if ($definition[$priceKey] === null) {
                    continue;
                }

                $prices[] = [
                    'id' => null,
                    'room_type_id' => $roomTypes[$definition['name']]->id,
                    'arrival_from' => $today->toDateString(),
                    'arrival_to' => $today->addDays(365)->toDateString(),
                    'amount' => $definition[$priceKey],
                ];
            }

            if (! app(SavePackagePricing::class)($owner, $package, $prices, [])) {
                throw new RuntimeException("Could not save the {$name} prices.");
            }

            $plans[$name] = $package;
        }

        return $plans;
    }

    /**
     * @return array{id: null, charge_item_id: int, meal_period_id: int|null, charging_unit: string, quantity: int, allocated_amount: string, starts_on: string, ends_on: string}
     */
    private function inclusion(ChargeItem $item, ?int $mealPeriodId, ChargingUnit $unit, int $quantity, string $amount, InclusionStart $start, InclusionEnd $end): array
    {
        return [
            'id' => null,
            'charge_item_id' => $item->id,
            'meal_period_id' => $mealPeriodId,
            'charging_unit' => $unit->value,
            'quantity' => $quantity,
            'allocated_amount' => $amount,
            'starts_on' => $start->value,
            'ends_on' => $end->value,
        ];
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
                'phone' => '+6014'.str_pad((string) (8300000 + $index * 211), 7, '0', STR_PAD_LEFT),
                'nationality_code' => $nationality,
                'country_code' => $nationality,
                'city' => $city,
            ]);
        }

        return $guests;
    }

    /**
     * A travel agent with a credit limit, and a conservation NGO billed for its researchers.
     *
     * @return array{agent: Company, ngo: Company}
     */
    private function seedCompanies(Property $property, User $owner): array
    {
        $create = fn (string $name, CompanyType $type, ?string $limit, int $terms, string $email): Company => app(CreateCompany::class)($owner, $property, [
            'name' => $name,
            'type' => $type,
            'contact_email' => $email,
            'contact_phone' => null,
            'credit_limit' => $limit,
            'payment_terms_days' => $terms,
            'direct_bill_enabled' => true,
        ]);

        return [
            'agent' => $create('Borneo Nature Tours', CompanyType::TravelAgent, '15000.00', 30, 'reservations@borneonaturetours.test'),
            'ngo' => $create('Kinabatangan Wildlife Trust', CompanyType::Corporate, '20000.00', 30, 'finance@kinabatanganwildlife.test'),
        ];
    }

    /**
     * About 16 reservations from the business date on: package couples and a family, a tour group of three chalets from
     * the travel agent, researchers on the dorm billed to the NGO, arrivals today, stays in house, a no-show, a
     * cancellation and an agent stay settled to the city ledger.
     *
     * @param  array<string, RoomType>  $roomTypes
     * @param  array<string, RatePlan>  $plans
     * @param  array<string, ChargeItem>  $items
     * @param  list<Guest>  $guests
     * @param  array{agent: Company, ngo: Company}  $companies
     */
    private function seedReservations(Property $property, User $owner, array $roomTypes, array $plans, array $items, array $guests, array $companies, CarbonImmutable $today): void
    {
        /**
         * Book `$lines` units of one room type on one plan. A bed type takes one bed per guest in `$guestIndexes`, one
         * adult each. A room takes `adults` (default 2) and the guests go on the first unit; with more units, each unit
         * gets the next guest. `auto_assign_rooms` assigns the rooms and beds.
         *
         * @param  list<int>  $guestIndexes
         * @param  array{source?: string, billing?: BillingMode, group?: string, notes?: string, adults?: int, company?: int, units?: int}  $options
         */
        $book = function (string $roomTypeName, string $planName, int $startOffset, int $nights, array $guestIndexes, array $options = []) use ($property, $owner, $roomTypes, $plans, $guests, $today): Reservation {
            $roomType = $roomTypes[$roomTypeName];
            $isBed = $roomType->sell_unit === SellUnit::Bed;
            $unitCount = $options['units'] ?? 1;

            $units = array_map(fn (): NewUnitData => new NewUnitData(
                roomTypeId: $roomType->id,
                ratePlanId: $plans[$planName]->id,
                arrivalDate: $today->addDays($startOffset),
                departureDate: $today->addDays($startOffset + $nights),
                adults: $isBed ? 1 : ($options['adults'] ?? 2),
                beds: $isBed ? count($guestIndexes) : 1,
            ), range(1, $unitCount));

            $unitIndexFor = fn (int $position): int => $isBed ? $position : min($position, $unitCount - 1);

            $result = app(CreateBooking::class)($owner, $property, new NewBookingData(
                bookingSource: $this->bookingSource($property, $options['source'] ?? null),
                units: $units,
                guests: array_map(fn (int $guestIndex, int $position): NewBookingGuestData => new NewBookingGuestData(guestId: $guests[$guestIndex]->id, unitIndex: $unitIndexFor($position)), $guestIndexes, array_keys($guestIndexes)),
                billingMode: $options['billing'] ?? BillingMode::MasterPays,
                groupName: $options['group'] ?? null,
                notes: $options['notes'] ?? null,
                companyId: $options['company'] ?? null,
            ));

            if ($result->isRefused()) {
                throw new RuntimeException("Booking for {$guests[$guestIndexes[0]]->full_name} was refused: {$result->refusal}.");
            }

            return $result->reservation;
        };

        // Future bookings.
        $book('River View Chalet', '2D1N Rainforest Package', 2, 1, [10, 11], ['notes' => 'Honeymoon. Hoping to see proboscis monkeys.']);
        $book('Jungle Chalet', '3D2N Wildlife Package', 3, 2, [8, 9], ['source' => 'Email']);
        $book('Family Longhouse Room', '3D2N Wildlife Package', 6, 2, [16, 17], ['adults' => 3, 'notes' => 'Family of three from Kuala Lumpur. Their son turns 12 on the trip.']);
        $book('Jungle Chalet', '3D2N Wildlife Package', 8, 2, [2, 4, 6], ['units' => 3, 'company' => $companies['agent']->id, 'group' => 'Borneo Nature Tours: Dutch birding group', 'notes' => 'Three chalets, two guests each. One bill to the agent. Early dawn cruise at 05:45.']);
        $book('River View Chalet', 'Room Only', 12, 3, [21], ['adults' => 1, 'source' => 'Email', 'notes' => 'Wildlife photographer. Needs a boat at dawn every day, booked separately.']);
        $book('Researcher Dorm', 'Researcher Bed', 4, 21, [20, 25, 26], ['company' => $companies['ngo']->id, 'group' => 'Orangutan nest survey', 'notes' => 'Three researchers for three weeks. Rooms and meals billed to the trust.']);
        $book('Jungle Chalet', '2D1N Rainforest Package', 15, 1, [12, 13]);

        // Arrivals today, left Confirmed to try check-in.
        $book('River View Chalet', '2D1N Rainforest Package', 0, 1, [14, 15], ['notes' => 'Jetty pickup at Sandakan at 11:00.']);
        $book('Jungle Chalet', '3D2N Wildlife Package', 0, 2, [23, 28]);
        $book('Family Longhouse Room', 'Room Only', 0, 2, [18, 19], ['adults' => 4, 'source' => 'Phone', 'notes' => 'Local family driving in from Sandakan.']);

        // In house.
        $whitfield = $book('River View Chalet', '3D2N Wildlife Package', 0, 2, [0, 1], ['notes' => 'Celebrating 25 years. Cake at dinner on the second night.']);
        $benjamin = $book('Researcher Dorm', 'Researcher Bed', 0, 14, [27], ['notes' => 'PhD student counting hornbill nests. Out at 05:00 most days.']);
        $walkIn = $book('Jungle Chalet', 'Room Only', 0, 2, [24], ['adults' => 1, 'source' => 'Walk-in']);

        $noShow = $book('Jungle Chalet', 'Room Only', 0, 1, [22]);
        $this->forEachUnit($noShow, fn (ReservationUnit $unit): UnitStayResult => app(MarkNoShow::class)($owner, $unit), 'The no-show');

        $cancelled = $book('River View Chalet', '2D1N Rainforest Package', 9, 1, [29]);
        $this->forEachUnit($cancelled, fn (ReservationUnit $unit): UnitStayResult => app(CancelUnit::class)($owner, $unit), 'The cancellation');

        $methods = PaymentMethod::query()->where('property_id', $property->id)->where('active', true)->get()->keyBy(fn (PaymentMethod $method): string => $method->type->value);

        $whitfieldUnit = $this->checkIn($owner, $whitfield)->first();
        $this->charge($owner, $whitfieldUnit, $items['BINOCULARS']);
        $this->charge($owner, $whitfieldUnit, $items['JETTY_TRANSFER'], 2);
        $this->pay($owner, $whitfield, $this->unitFolio($whitfieldUnit), $methods[PaymentMethodType::Card->value], PaymentPurpose::Advance, '1000.00');

        $benjaminUnit = $this->checkIn($owner, $benjamin)->first();
        $this->charge($owner, $benjaminUnit, $items['PACKED_LUNCH'], 3);
        $this->pay($owner, $benjamin, $this->unitFolio($benjaminUnit), $methods[PaymentMethodType::BankTransfer->value], PaymentPurpose::Advance, '500.00');

        $walkInUnit = $this->checkIn($owner, $walkIn)->first();
        $this->charge($owner, $walkInUnit, $items['RIVER_CRUISE']);
        $this->charge($owner, $walkInUnit, $items['NIGHT_WALK']);
        $this->pay($owner, $walkIn, $this->unitFolio($walkInUnit), $methods[PaymentMethodType::Cash->value], PaymentPurpose::Advance, '300.00');

        $this->seedCityLedger($owner, $book, $items, $companies['agent'], $methods[PaymentMethodType::BankTransfer->value]);
    }

    /**
     * A one-night agent stay from today: checked in, the jetty transfer and a river cruise moved to the agent's folio,
     * checked out and settled to the city ledger. The agent has paid part of the invoice.
     *
     * @param  callable(string, string, int, int, list<int>, array<string, mixed>): Reservation  $book
     * @param  array<string, ChargeItem>  $items
     */
    private function seedCityLedger(User $owner, callable $book, array $items, Company $agent, PaymentMethod $bankTransfer): void
    {
        $reservation = $book('River View Chalet', 'Room Only', 0, 1, [5, 7], ['company' => $agent->id, 'notes' => 'Agent guests on a one-night stopover before Danum Valley.']);
        $unit = $this->checkIn($owner, $reservation)->first();
        $lines = [$this->charge($owner, $unit, $items['JETTY_TRANSFER'], 2), $this->charge($owner, $unit, $items['RIVER_CRUISE'], 2)];
        $agentFolio = app(CreateFolio::class)->thirdParty($reservation, null, $agent->id, $owner);
        $transferred = app(TransferLines::class)($owner, $lines, $agentFolio);

        if ($transferred->isRefused()) {
            throw new RuntimeException("The transfer to {$agent->name} was refused: {$transferred->refusal}.");
        }

        $this->checkOut($unit);

        $unitFolio = $this->unitFolio($unit)->refresh();

        if ($unitFolio->status === FolioStatus::Open && BigDecimal::of(app(FolioBalance::class)->forFolio($unitFolio))->isZero()) {
            $closed = app(CloseFolio::class)($owner, $unitFolio);

            if ($closed->isRefused()) {
                throw new RuntimeException("Closing the agent guests' folio was refused: {$closed->refusal}.");
            }
        }

        $settled = app(SettleToCityLedger::class)($owner, $agentFolio->refresh());

        if ($settled->isRefused() || ! $settled->invoice instanceof ArInvoice) {
            throw new RuntimeException("Settling {$agent->name}'s folio was refused: {$settled->refusal}.");
        }

        $payment = app(RecordArPayment::class)($owner, $agent, new NewArPaymentData(
            paymentMethodId: $bankTransfer->id,
            amount: '200.00',
            idempotencyKey: (string) Str::uuid(),
            reference: 'TT-'.Str::upper(Str::random(6)),
        ), allocate: true);

        if ($payment->isRefused()) {
            throw new RuntimeException("The payment from {$agent->name} was refused: {$payment->refusal}.");
        }
    }

    /**
     * Room statuses for the board: dirty chalets after check-out, an inspected chalet, and a remark.
     */
    private function seedHousekeeping(Property $property, User $owner): void
    {
        $rooms = Room::query()->where('property_id', $property->id)->get()->keyBy('number');

        foreach (['R4' => HousekeepingStatus::Dirty, 'J5' => HousekeepingStatus::Dirty, 'L2' => HousekeepingStatus::Inspected] as $number => $status) {
            $refusal = app(UpdateRoomStatus::class)($owner, $rooms[$number], $status);

            if ($refusal !== null) {
                throw new RuntimeException("Room {$number} could not be set to {$status->value}.");
            }
        }

        app(SetRoomRemark::class)($owner, $rooms['R4'], 'Check the mosquito net for tears and sweep the balcony. Monkeys got in again.');
    }

    /**
     * Turn on the property amenities and each room type's amenities. Every name must be on the platform amenity list.
     *
     * @param  array<string, RoomType>  $roomTypes
     */
    private function seedAmenities(Property $property, User $owner, array $roomTypes): void
    {
        foreach (self::PROPERTY_AMENITIES as $name) {
            if (! app(SetPropertyAmenity::class)($owner, $property, $this->amenityId(AmenityType::Property, $name), true)) {
                throw new RuntimeException("Could not turn on the property amenity {$name}.");
            }
        }

        foreach ($roomTypes as $roomTypeName => $roomType) {
            foreach ([...self::ROOM_AMENITIES['*'], ...self::ROOM_AMENITIES[$roomTypeName]] as $name) {
                if (! app(SetRoomTypeAmenity::class)($owner, $roomType, $this->amenityId(AmenityType::Room, $name), true)) {
                    throw new RuntimeException("Could not turn on the room amenity {$name} for {$roomTypeName}.");
                }
            }
        }
    }

    private function amenityId(AmenityType $type, string $name): int
    {
        $amenity = Amenity::query()->ofType($type)->where('name', $name)->where('active', true)->first();

        if ($amenity === null) {
            throw new RuntimeException("The {$type->value} amenity {$name} is not on the amenity list. Run AmenitySeeder first.");
        }

        return $amenity->id;
    }

    /**
     * Turn on the booking engine in Microsite mode, make every plan that can be sold online bookable, and save the About
     * text and the location on the river. The site is not published: it needs a template, images and photos first.
     */
    private function seedBookingSite(Property $property, User $owner): void
    {
        Feature::for($property)->activate(BookingEngine::class);
        app(SetUpBookingSite::class)($property);
        $property->refresh();

        foreach (RatePlan::query()->where('property_id', $property->id)->offerableOnline()->get() as $plan) {
            if (app(SetRatePlanBookableOnline::class)($owner, $property, $plan->id, true) !== BookableOnlineResult::Saved) {
                throw new RuntimeException("Could not make {$plan->name} bookable online.");
            }
        }

        $mapsUrl = 'https://www.google.com/maps/search/?api=1&query=5.5108,118.1964';

        app(SaveBookingWebsiteGeneral::class)($property, $owner, (string) $property->slug, $mapsUrl, new MapsPlace(self::NAME, '5.5108', '118.1964', $mapsUrl));
        app(UpdatePropertyProfile::class)($owner, $property, ['about' => self::ABOUT]);
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
