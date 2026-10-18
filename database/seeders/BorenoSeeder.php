<?php

namespace Database\Seeders;

use App\Actions\Catalogues\CreateChargeItem;
use App\Actions\Catalogues\CreateDiscount;
use App\Actions\Catalogues\UpdateRoomRevenueTaxes;
use App\Actions\Catalogues\UpdateTax;
use App\Actions\CityLedger\CreateCompany;
use App\Actions\CityLedger\IssueCreditNote;
use App\Actions\CityLedger\RecordArPayment;
use App\Actions\CityLedger\SettleToCityLedger;
use App\Actions\Folio\CloseFolio;
use App\Actions\Folio\CreateFolio;
use App\Actions\Folio\TransferLines;
use App\Actions\FrontDesk\CancelUnit;
use App\Actions\FrontDesk\MarkNoShow;
use App\Actions\FrontDesk\UnitStayResult;
use App\Actions\Guests\CreateGuest;
use App\Actions\Guests\SetVip;
use App\Actions\Housekeeping\SetNeedsMaintenance;
use App\Actions\Housekeeping\SetRoomRemark;
use App\Actions\Housekeeping\UpdateRoomStatus;
use App\Actions\NightAudit\OpenBusinessDate;
use App\Actions\Property\GenerateDocumentPrefix;
use App\Actions\Property\IssuePropertyCode;
use App\Actions\Property\SeedPropertyDefaults;
use App\Actions\Rates\CreateRatePlan;
use App\Actions\Rates\SaveRatePlanInclusions;
use App\Actions\Rates\SaveRates;
use App\Actions\Rates\UpdateRatePlan;
use App\Actions\Reservations\CreateBooking;
use App\Actions\Rooms\CreateRoomType;
use App\Actions\Rooms\GenerateRooms;
use App\Actions\Staff\AssignOwnerRole;
use App\Actions\Staff\AssignStaffRole;
use App\Data\Catalogues\TaxInputData;
use App\Data\CityLedger\NewArPaymentData;
use App\Data\Reservations\NewBookingData;
use App\Data\Reservations\NewBookingGuestData;
use App\Data\Reservations\NewUnitData;
use App\Data\Reservations\UnitPickData;
use App\Enums\BillingMode;
use App\Enums\ChargingUnit;
use App\Enums\CompanyType;
use App\Enums\DefaultRoleEnum;
use App\Enums\DiscountScope;
use App\Enums\DiscountType;
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
use App\Enums\TransactionCategory;
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
use App\Services\Folio\FolioBalance;
use App\Services\Property\PropertyToday;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\SeedsThroughActions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Seeds Boreno Hotel for the demo environment: a 30-room city hotel in Kota Kinabalu with corporate guests and the
 * city ledger. A live property with an open business date, its catalogues, rooms, two rate plans, guests, three
 * companies and about 20 reservations, dated relative to the business date. Three short company stays are checked in,
 * charged, checked out and settled to the city ledger, so the AR invoices are paid, part paid and credited. Room
 * charges are not seeded: they post at the night audit, and the seeder runs none. Every step uses the production
 * Actions. The Owner signs in as `boreno@demo.estatell.com`, and every staff account uses `DEMO_PASSWORD`. The name is
 * Boreno, not Borneo. See docs/01-platform/06-demo-properties.md.
 */
class BorenoSeeder extends Seeder
{
    use SeedsThroughActions;

    private const string NAME = 'Boreno Hotel';

    /**
     * Room types: name, rooms, group, first number, occupancy, weekday and weekend nightly price.
     *
     * @var list<array{name: string, description: string, count: int, group: string, first: int, base: int, adults: int, children: int, weekday: string, weekend: string}>
     */
    private const array ROOM_TYPES = [
        ['name' => 'Standard Room', 'description' => 'Queen or twin beds, with a city view.', 'count' => 18, 'group' => 'Level 3', 'first' => 301, 'base' => 2, 'adults' => 2, 'children' => 1, 'weekday' => '220.00', 'weekend' => '198.00'],
        ['name' => 'Deluxe Room', 'description' => 'King bed and a work desk, facing the sea.', 'count' => 9, 'group' => 'Level 5', 'first' => 501, 'base' => 2, 'adults' => 3, 'children' => 1, 'weekday' => '320.00', 'weekend' => '288.00'],
        ['name' => 'Executive Suite', 'description' => 'Separate living room and lounge access.', 'count' => 3, 'group' => 'Level 8', 'first' => 801, 'base' => 2, 'adults' => 4, 'children' => 2, 'weekday' => '650.00', 'weekend' => '585.00'],
    ];

    /**
     * Guests: name, nationality (ISO code), and the city shown on the profile.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const array GUESTS = [
        ['Ahmad Razak', 'MY', 'Kota Kinabalu'], ['Tan Mei Ling', 'MY', 'Kuala Lumpur'], ['Rajesh Kumar', 'MY', 'Petaling Jaya'],
        ['Norazlina binti Hassan', 'MY', 'Sandakan'], ['Joseph Gimbang', 'MY', 'Penampang'], ['Chong Wei Jie', 'MY', 'Tawau'],
        ['Aminah binti Sulaiman', 'MY', 'Keningau'], ['Daniel Lo', 'MY', 'Kuching'], ['Grace Lim', 'SG', 'Singapore'],
        ['Hendra Wijaya', 'ID', 'Jakarta'], ['Maria Santos', 'PH', 'Manila'], ['Nguyen Van An', 'VN', 'Ho Chi Minh City'],
        ['Wang Jun', 'CN', 'Guangzhou'], ['Sophie Martin', 'FR', 'Lyon'], ['Michael Brown', 'AU', 'Perth'],
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
        $corporate = $this->seedDiscount($property, $owner);
        $roomTypes = $this->seedRooms($property, $owner);
        $plans = $this->seedRatePlans($property, $owner, $roomTypes, $items, $today);
        $guests = $this->seedGuests($property, $owner);
        $companies = $this->seedCompanies($property, $owner);
        $this->seedReservations($property, $owner, $roomTypes, $plans, $items, $corporate, $guests, $companies, $today);
        $this->seedHousekeeping($property, $owner);

        $this->command?->info(self::NAME.' seeded: '.Reservation::query()->where('property_id', $property->id)->count().' reservations, '.ArInvoice::query()->where('property_id', $property->id)->count().' AR invoices.');
    }

    private function createProperty(): Property
    {
        $property = (new Property)->forceFill([
            'code' => app(IssuePropertyCode::class)(),
            'name' => self::NAME,
            'type' => PropertyType::Hotel,
            'status' => PropertyStatus::Onboarding,
            'address_line1' => '18 Jalan Gaya',
            'city' => 'Kota Kinabalu',
            'state_code' => 'Sabah',
            'postal_code' => '88000',
            'country_code' => 'MY',
            'contact_email' => 'hello@boreno.test',
            'contact_phone' => '+6088223456',
            'onboarding_started_at' => now()->subDays(14),
        ]);
        $property->save();

        app(GenerateDocumentPrefix::class)($property);
        app(SeedPropertyDefaults::class)($property, PropertyType::Hotel, 'MY');
        $property->forceFill(['status' => PropertyStatus::Live, 'went_live_at' => now()])->save();

        return $property->refresh();
    }

    /**
     * Create the four staff accounts and return the Owner.
     */
    private function createStaff(Property $property): User
    {
        $names = [
            DefaultRoleEnum::Owner->value => 'Linda Chin',
            DefaultRoleEnum::Manager->value => 'Rizal Hamid',
            DefaultRoleEnum::FrontDesk->value => 'Nadia Front Desk',
            DefaultRoleEnum::Housekeeping->value => 'Rosli Housekeeping',
        ];
        $users = [];

        foreach ([DefaultRoleEnum::Owner, DefaultRoleEnum::Manager, DefaultRoleEnum::FrontDesk, DefaultRoleEnum::Housekeeping] as $role) {
            $user = new User(['name' => $names[$role->value], 'email' => DemoSeeder::loginEmail('boreno', $role), 'password' => DemoSeeder::password()]);
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
            ['Minibar drink', 'MINIBAR_DRINK', TransactionCategory::FoodAndBeverage, '12.00', ChargingUnit::PerItem],
            ['Laundry (per piece)', 'LAUNDRY_PIECE', TransactionCategory::Fee, '25.00', ChargingUnit::PerItem],
            ['Parking', 'PARKING', TransactionCategory::Fee, '15.00', ChargingUnit::PerNight],
            ['Airport transfer', 'AIRPORT_TRANSFER', TransactionCategory::Fee, '80.00', ChargingUnit::PerStay],
            ['Meeting room (full day)', 'MEETING_ROOM', TransactionCategory::Other, '450.00', ChargingUnit::PerStay],
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

    private function seedDiscount(Property $property, User $owner): int
    {
        return app(CreateDiscount::class)($property, $owner, [
            'name' => 'Corporate',
            'code' => 'CORPORATE',
            'type' => DiscountType::Percent->value,
            'value' => '15.00',
            'allow_override' => false,
            'scope' => DiscountScope::RoomOnly->value,
            'active' => true,
            'transaction_code_ids' => [],
        ])->id;
    }

    /**
     * @return array<string, RoomType> Keyed by name.
     */
    private function seedRooms(Property $property, User $owner): array
    {
        $roomTypes = [];

        foreach (self::ROOM_TYPES as $definition) {
            $roomType = app(CreateRoomType::class)($property, $owner, [
                'name' => $definition['name'],
                'description' => $definition['description'],
                'sell_unit' => SellUnit::Room->value,
                'max_adults' => $definition['adults'],
                'max_children' => $definition['children'],
                'base_occupancy' => $definition['base'],
                'smoking_allowed' => false,
                'pets_allowed' => false,
            ]);

            $plan = [];

            for ($index = 0; $index < $definition['count']; $index++) {
                $plan[] = ['number' => (string) ($definition['first'] + $index), 'group' => $definition['group']];
            }

            $result = app(GenerateRooms::class)($property, $owner, $roomType, null, $plan);

            if ($result['conflicts'] !== []) {
                throw new RuntimeException('Room numbers already taken: '.implode(', ', $result['conflicts']));
            }

            $roomTypes[$definition['name']] = $roomType;
        }

        return $roomTypes;
    }

    /**
     * Rename the starter plan to Room Only, add Bed & Breakfast, and price the next 200 days. City hotels sell
     * Friday and Saturday nights for about 10% less.
     *
     * @param  array<string, RoomType>  $roomTypes
     * @param  array<string, ChargeItem>  $items
     * @return array<string, RatePlan> Keyed by name.
     */
    private function seedRatePlans(Property $property, User $owner, array $roomTypes, array $items, CarbonImmutable $today): array
    {
        $roomTypeIds = array_values(array_map(fn (RoomType $roomType): int => $roomType->id, $roomTypes));

        $roomOnly = RatePlan::query()->where('property_id', $property->id)->orderBy('position')->firstOrFail();
        app(UpdateRatePlan::class)($owner, $roomOnly, ['name' => 'Room Only', 'description' => 'Room only, no meals.', 'extra_adult_amount' => '70.00', 'extra_child_amount' => '35.00']);

        $bedAndBreakfast = app(CreateRatePlan::class)($property, $owner, [
            'name' => 'Bed & Breakfast',
            'description' => 'Buffet breakfast for every guest.',
            'extra_adult_amount' => '70.00',
            'extra_child_amount' => '35.00',
            'pricing_basis' => PricingBasis::PerRoom->value,
        ], $roomTypeIds);

        $breakfastPeriod = MealPeriod::query()->where('property_id', $property->id)->where('name', 'Breakfast')->value('id');

        app(SaveRatePlanInclusions::class)($owner, $bedAndBreakfast, [[
            'id' => null,
            'charge_item_id' => $items['BREAKFAST']->id,
            'meal_period_id' => $breakfastPeriod,
            'charging_unit' => ChargingUnit::PerGuestNight->value,
            'quantity' => 1,
            'allocated_amount' => '35.00',
            'starts_on' => InclusionStart::DayAfterArrival->value,
            'ends_on' => InclusionEnd::DepartureDay->value,
        ]]);

        $plans = ['Room Only' => $roomOnly, 'Bed & Breakfast' => $bedAndBreakfast];

        foreach ([['Room Only', '0.00'], ['Bed & Breakfast', '60.00']] as [$name, $supplement]) {
            $rates = [];

            foreach (self::ROOM_TYPES as $definition) {
                $days = [];

                foreach (range(1, 7) as $weekday) {
                    $base = in_array($weekday, [5, 6], true) ? $definition['weekend'] : $definition['weekday'];
                    $days[$weekday] = ['amount' => (string) BigDecimal::of($base)->plus($supplement)->toScale(2), 'occupancy' => []];
                }

                $rates[] = ['room_type_id' => $roomTypes[$definition['name']]->id, 'days' => $days];
            }

            if (app(SaveRates::class)($owner, $plans[$name], $today->toDateString(), $today->addDays(200)->toDateString(), $rates) === false) {
                throw new RuntimeException("Could not save rates for {$name}.");
            }
        }

        return $plans;
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
                'phone' => '+6013'.str_pad((string) (5200000 + $index * 211), 7, '0', STR_PAD_LEFT),
                'nationality_code' => $nationality,
                'country_code' => $nationality,
                'city' => $city,
            ]);
        }

        app(SetVip::class)($owner, $guests[0], true);

        return $guests;
    }

    /**
     * A corporate account, a travel agent with a small credit limit, and a government department.
     *
     * @return array{energy: Company, travel: Company, government: Company}
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
            'energy' => $create('Sabah Energy Sdn Bhd', CompanyType::Corporate, '20000.00', 30, 'travel@sabahenergy.test'),
            'travel' => $create('Kinabalu Travel & Tours', CompanyType::TravelAgent, '1200.00', 14, 'bookings@kinabalutravel.test'),
            'government' => $create('Jabatan Pelancongan Sabah', CompanyType::Government, null, 60, 'akaun@pelancongan.test'),
        ];
    }

    /**
     * About 20 reservations from the business date on: future and company bookings, a conference group, arrivals,
     * two in-house stays, a no-show, a cancellation, and three company stays settled to the city ledger.
     *
     * @param  array<string, RoomType>  $roomTypes
     * @param  array<string, RatePlan>  $plans
     * @param  array<string, ChargeItem>  $items
     * @param  list<Guest>  $guests
     * @param  array{energy: Company, travel: Company, government: Company}  $companies
     */
    private function seedReservations(Property $property, User $owner, array $roomTypes, array $plans, array $items, int $corporate, array $guests, array $companies, CarbonImmutable $today): void
    {
        $rooms = Room::query()->where('property_id', $property->id)->orderBy('position')->get()->groupBy('room_type_id');
        $roomCursor = [];

        $nextRoom = function (RoomType $roomType) use ($rooms, &$roomCursor): int {
            $roomCursor[$roomType->id] = ($roomCursor[$roomType->id] ?? -1) + 1;

            return $rooms[$roomType->id]->values()[$roomCursor[$roomType->id] % $rooms[$roomType->id]->count()]->id;
        };

        $book = function (string $roomTypeName, string $planName, int $startOffset, int $nights, int $adults, int $guestIndex, array $options = []) use ($property, $owner, $roomTypes, $plans, $guests, $today, $nextRoom): Reservation {
            $roomType = $roomTypes[$roomTypeName];

            $result = app(CreateBooking::class)($owner, $property, new NewBookingData(
                bookingSource: $this->bookingSource($property, $options['source'] ?? null),
                units: [new NewUnitData(
                    roomTypeId: $roomType->id,
                    ratePlanId: $plans[$planName]->id,
                    arrivalDate: $today->addDays($startOffset),
                    departureDate: $today->addDays($startOffset + $nights),
                    adults: $adults,
                    discountId: $options['discount'] ?? null,
                )],
                guests: [new NewBookingGuestData(guestId: $guests[$guestIndex]->id, unitIndex: 0)],
                picks: ($options['assign'] ?? true) ? [new UnitPickData(unitIndex: 0, roomId: $nextRoom($roomType))] : [],
                notes: $options['notes'] ?? null,
                companyId: $options['company'] ?? null,
            ));

            if ($result->isRefused()) {
                throw new RuntimeException("Booking for {$guests[$guestIndex]->full_name} was refused: {$result->refusal}.");
            }

            return $result->reservation;
        };

        $energy = $companies['energy']->id;
        $travel = $companies['travel']->id;

        // Future bookings.
        $deposit = $book('Deluxe Room', 'Bed & Breakfast', 2, 3, 2, 8, ['notes' => 'Honeymoon. Late check-in around 23:00.']);
        $book('Standard Room', 'Room Only', 3, 2, 1, 2, ['company' => $energy, 'discount' => $corporate]);
        $book('Standard Room', 'Bed & Breakfast', 4, 4, 2, 9, ['source' => 'Phone']);
        $book('Executive Suite', 'Bed & Breakfast', 5, 2, 2, 13);
        $book('Standard Room', 'Room Only', 9, 1, 1, 6, ['company' => $companies['government']->id]);
        $book('Deluxe Room', 'Room Only', 12, 5, 2, 14, ['assign' => false]);
        $book('Standard Room', 'Bed & Breakfast', 15, 3, 2, 10, ['company' => $travel, 'assign' => false]);
        $book('Deluxe Room', 'Bed & Breakfast', 20, 2, 2, 12);
        $this->conference($property, $owner, $roomTypes['Deluxe Room'], $plans['Bed & Breakfast'], $guests, $energy, $today->addDays(7), $nextRoom);

        // Arrivals today, left Confirmed to try check-in.
        $book('Standard Room', 'Room Only', 0, 2, 1, 3, ['company' => $energy, 'discount' => $corporate]);
        $book('Deluxe Room', 'Bed & Breakfast', 0, 3, 2, 11, ['notes' => 'Arrives on the evening flight.']);

        // In house.
        $ahmad = $book('Executive Suite', 'Bed & Breakfast', 0, 3, 1, 0, ['company' => $energy, 'notes' => 'Regional manager. Prefers a high floor.']);
        $leisure = $book('Standard Room', 'Room Only', 0, 2, 2, 4, ['source' => 'Walk-in']);

        $noShow = $book('Standard Room', 'Room Only', 0, 1, 1, 5);
        $this->forEachUnit($noShow, fn (ReservationUnit $unit): UnitStayResult => app(MarkNoShow::class)($owner, $unit), 'The no-show');

        $cancelled = $book('Deluxe Room', 'Room Only', 10, 2, 2, 7);
        $this->forEachUnit($cancelled, fn (ReservationUnit $unit): UnitStayResult => app(CancelUnit::class)($owner, $unit), 'The cancellation');

        $methods = PaymentMethod::query()->where('property_id', $property->id)->where('active', true)->get()->keyBy(fn (PaymentMethod $method): string => $method->type->value);

        $this->advance($owner, $deposit, $deposit->units()->firstOrFail(), $methods[PaymentMethodType::Card->value], '200.00');

        $ahmadUnit = $this->checkIn($owner, $ahmad)->first();
        $this->charge($owner, $ahmadUnit, $items['PARKING']);
        $this->charge($owner, $ahmadUnit, $items['LAUNDRY_PIECE'], 3);

        $leisureUnit = $this->checkIn($owner, $leisure)->first();
        $this->charge($owner, $leisureUnit, $items['MINIBAR_DRINK'], 2);
        $this->pay($owner, $leisure, $this->unitFolio($leisureUnit), $methods[PaymentMethodType::Cash->value], PaymentPurpose::Advance, '300.00');

        $this->seedCityLedger($owner, $book, $items, $companies, $methods[PaymentMethodType::BankTransfer->value]);
    }

    /**
     * A conference block: three Deluxe units, MasterPays, booked by the corporate account.
     *
     * @param  list<Guest>  $guests
     */
    private function conference(Property $property, User $owner, RoomType $roomType, RatePlan $plan, array $guests, int $companyId, CarbonImmutable $arrival, callable $nextRoom): void
    {
        $units = [];
        $picks = [];

        foreach (range(0, 2) as $index) {
            $units[] = new NewUnitData(roomTypeId: $roomType->id, ratePlanId: $plan->id, arrivalDate: $arrival, departureDate: $arrival->addDays(2), adults: 1);
            $picks[] = new UnitPickData(unitIndex: $index, roomId: $nextRoom($roomType));
        }

        $result = app(CreateBooking::class)($owner, $property, new NewBookingData(
            bookingSource: $this->bookingSource($property),
            units: $units,
            guests: [
                new NewBookingGuestData(guestId: $guests[1]->id, unitIndex: 0),
                new NewBookingGuestData(guestId: $guests[2]->id, unitIndex: 1),
                new NewBookingGuestData(guestId: $guests[5]->id, unitIndex: 2),
            ],
            picks: $picks,
            billingMode: BillingMode::MasterPays,
            groupName: 'Sabah Energy Leadership Meeting',
            notes: 'Three rooms and the meeting room. One bill to the company.',
            companyId: $companyId,
        ));

        if ($result->isRefused()) {
            throw new RuntimeException("The conference block was refused: {$result->refusal}.");
        }
    }

    /**
     * Three one-night company stays from today: checked in, charged to a company folio, checked out and settled to
     * the city ledger. The corporate invoice is paid, the travel agent's is part paid (near its credit limit), and
     * the government invoice has a credit note.
     *
     * @param  callable(string, string, int, int, int, int, array<string, mixed>): Reservation  $book
     * @param  array<string, ChargeItem>  $items
     * @param  array{energy: Company, travel: Company, government: Company}  $companies
     */
    private function seedCityLedger(User $owner, callable $book, array $items, array $companies, PaymentMethod $bankTransfer): void
    {
        $energyInvoice = $this->settledStay($owner, $book('Deluxe Room', 'Room Only', 0, 1, 1, 1, ['company' => $companies['energy']->id]), $companies['energy'], [
            [$items['MEETING_ROOM'], 1], [$items['BREAKFAST'], 10], [$items['PARKING'], 1],
        ]);
        $travelInvoice = $this->settledStay($owner, $book('Standard Room', 'Room Only', 0, 1, 2, 12, ['company' => $companies['travel']->id]), $companies['travel'], [
            [$items['AIRPORT_TRANSFER'], 6], [$items['BREAKFAST'], 12], [$items['LAUNDRY_PIECE'], 4],
        ]);
        $governmentInvoice = $this->settledStay($owner, $book('Standard Room', 'Room Only', 0, 1, 1, 6, ['company' => $companies['government']->id]), $companies['government'], [
            [$items['MEETING_ROOM'], 1], [$items['BREAKFAST'], 4], [$items['AIRPORT_TRANSFER'], 1],
        ]);

        $this->receive($owner, $companies['energy'], $bankTransfer, (string) $energyInvoice->amount);
        $this->receive($owner, $companies['travel'], $bankTransfer, '300.00');

        $credit = app(IssueCreditNote::class)($owner, $governmentInvoice, '80.00', 'Airport transfer was cancelled by the guest.');

        if ($credit->isRefused()) {
            throw new RuntimeException("The credit note was refused: {$credit->refusal}.");
        }
    }

    /**
     * Check a company stay in, post its charges, move them to a company folio, check it out and settle that folio to
     * the city ledger.
     *
     * @param  list<array{0: ChargeItem, 1: int}>  $charges
     */
    private function settledStay(User $owner, Reservation $reservation, Company $company, array $charges): ArInvoice
    {
        $unit = $this->checkIn($owner, $reservation)->first();
        $lines = array_map(fn (array $charge) => $this->charge($owner, $unit, $charge[0], $charge[1]), $charges);
        $companyFolio = app(CreateFolio::class)->thirdParty($reservation, null, $company->id, $owner);
        $transferred = app(TransferLines::class)($owner, $lines, $companyFolio);

        if ($transferred->isRefused()) {
            throw new RuntimeException("The transfer to {$company->name} was refused: {$transferred->refusal}.");
        }

        $this->checkOut($unit);

        $unitFolio = $this->unitFolio($unit)->refresh();

        if ($unitFolio->status === FolioStatus::Open && BigDecimal::of(app(FolioBalance::class)->forFolio($unitFolio))->isZero()) {
            $closed = app(CloseFolio::class)($owner, $unitFolio);

            if ($closed->isRefused()) {
                throw new RuntimeException("Closing the folio of {$company->name}'s guest was refused: {$closed->refusal}.");
            }
        }

        $settled = app(SettleToCityLedger::class)($owner, $companyFolio->refresh());

        if ($settled->isRefused() || $settled->invoice === null) {
            throw new RuntimeException("Settling {$company->name}'s folio was refused: {$settled->refusal}.");
        }

        return $settled->invoice;
    }

    private function receive(User $owner, Company $company, PaymentMethod $method, string $amount): void
    {
        $result = app(RecordArPayment::class)($owner, $company, new NewArPaymentData(
            paymentMethodId: $method->id,
            amount: $amount,
            idempotencyKey: (string) Str::uuid(),
            reference: 'TT-'.Str::upper(Str::random(6)),
        ), allocate: true);

        if ($result->isRefused()) {
            throw new RuntimeException("The payment from {$company->name} was refused: {$result->refusal}.");
        }
    }

    /**
     * Mixed room statuses for the board: a few dirty and inspected rooms, a remark, and one room that needs maintenance.
     */
    private function seedHousekeeping(Property $property, User $owner): void
    {
        $rooms = Room::query()->where('property_id', $property->id)->get()->keyBy('number');

        foreach (['306' => HousekeepingStatus::Dirty, '307' => HousekeepingStatus::Dirty, '503' => HousekeepingStatus::Dirty, '308' => HousekeepingStatus::Inspected, '504' => HousekeepingStatus::Inspected] as $number => $status) {
            $refusal = app(UpdateRoomStatus::class)($owner, $rooms[$number], $status);

            if ($refusal !== null) {
                throw new RuntimeException("Room {$number} could not be set to {$status->value}.");
            }
        }

        app(SetRoomRemark::class)($owner, $rooms['308'], 'Extra pillows requested for the next guest.');
        app(SetNeedsMaintenance::class)($owner, $rooms['318'], true);
        app(SetRoomRemark::class)($owner, $rooms['318'], 'Air conditioner leaking. Technician booked.');
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
