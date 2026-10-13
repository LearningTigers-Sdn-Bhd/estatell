<?php

namespace Database\Seeders;

use App\Actions\Catalogues\CreateChargeItem;
use App\Actions\Catalogues\CreateDiscount;
use App\Actions\Catalogues\CreateTax;
use App\Actions\Catalogues\UpdateRoomRevenueTaxes;
use App\Actions\Catalogues\UpdateTax;
use App\Actions\Folio\CloseFolio;
use App\Actions\Folio\CreateFolio;
use App\Actions\Folio\CreateRoutingRule;
use App\Actions\Folio\PostCharge;
use App\Actions\Folio\ReverseLine;
use App\Actions\Folio\TransferLines;
use App\Actions\FrontDesk\CancelUnit;
use App\Actions\FrontDesk\CheckInAllUnits;
use App\Actions\FrontDesk\MarkNoShow;
use App\Actions\FrontDesk\UnitStayResult;
use App\Actions\Guests\BlacklistGuest;
use App\Actions\Guests\CreateGuest;
use App\Actions\Guests\SetVip;
use App\Actions\NightAudit\OpenBusinessDate;
use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\RefundPayment;
use App\Actions\Property\GenerateDocumentPrefix;
use App\Actions\Property\IssuePropertyCode;
use App\Actions\Property\SeedPropertyDefaults;
use App\Actions\Rates\CreateRatePlan;
use App\Actions\Rates\SavePackagePricing;
use App\Actions\Rates\SaveRatePlanInclusions;
use App\Actions\Rates\SaveRatePlanStayDiscounts;
use App\Actions\Rates\SaveRates;
use App\Actions\Rates\UpdateRatePlan;
use App\Actions\Reservations\CreateBooking;
use App\Actions\Reservations\SyncReservationStatus;
use App\Actions\Rooms\CreateRoomType;
use App\Actions\Rooms\GenerateRooms;
use App\Actions\Staff\AssignOwnerRole;
use App\Actions\Staff\AssignStaffRole;
use App\Data\Folio\NewChargeData;
use App\Data\Payments\NewPaymentData;
use App\Data\Reservations\NewAddOnData;
use App\Data\Reservations\NewBookingData;
use App\Data\Reservations\NewBookingGuestData;
use App\Data\Reservations\NewUnitData;
use App\Data\Reservations\UnitPickData;
use App\Enums\BillingMode;
use App\Enums\ChargingUnit;
use App\Enums\DefaultRoleEnum;
use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Enums\InclusionEnd;
use App\Enums\InclusionStart;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Enums\PricingBasis;
use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Enums\ReservationSource;
use App\Enums\SellUnit;
use App\Enums\StayType;
use App\Enums\TaxRateType;
use App\Enums\TransactionCategory;
use App\Enums\UnitStatus;
use App\Models\ChargeItem;
use App\Models\Discount;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Guest;
use App\Models\MealPeriod;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tax;
use App\Models\UnitAddOn;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\Availability\DayUseAvailability;
use App\Services\Folio\FolioBalance;
use App\Services\Property\PropertyToday;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Seeds Sandbay Resort, a 30-room resort in Semporna, Sabah: a live property with an open business date, its
 * catalogues, rooms, rate plans, rates, staff, guests and about 20 reservations. Dates are relative to the
 * property's business date. It runs on demand (`php artisan db:seed --class=SandbaySeeder`), uses the production
 * Actions, and skips when a property named Sandbay Resort exists. Two in-house units have folios with manual
 * postings, a reversal, a transfer to a third-party folio, routing, payments, a refund and a closed folio. Room
 * charges are not seeded: the night audit does not post them yet. Check-in, no-show and cancellation go through
 * the front desk Actions. Check-out writes the status itself, because `CheckOutUnit` refuses a stay before its
 * departure date and the walk-in departs tomorrow.
 */
class SandbaySeeder extends Seeder
{
    /**
     * The staff accounts created by this run, for the table printed at the end.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private array $credentials = [];

    private const string NAME = 'Sandbay Resort';

    /**
     * Shared by every Sandbay staff account. For local development only.
     */
    private const string STAFF_PASSWORD = '12345678';

    /**
     * Room types: name, rooms, group, number prefix, occupancy, weekday and weekend nightly price, package price for each guest.
     *
     * @var list<array{name: string, description: string, count: int, group: string, first: int, base: int, adults: int, children: int, weekday: string, weekend: string, package: string}>
     */
    private const array ROOM_TYPES = [
        ['name' => 'Garden Room', 'description' => 'Double or twin room set in the tropical garden.', 'count' => 10, 'group' => 'Garden Block', 'first' => 101, 'base' => 2, 'adults' => 3, 'children' => 1, 'weekday' => '200.00', 'weekend' => '230.00', 'package' => '750.00'],
        ['name' => 'Sea View Room', 'description' => 'Room with a balcony facing the bay.', 'count' => 10, 'group' => 'Sea View Wing', 'first' => 201, 'base' => 2, 'adults' => 3, 'children' => 1, 'weekday' => '320.00', 'weekend' => '368.00', 'package' => '880.00'],
        ['name' => 'Family Suite', 'description' => 'Two bedrooms for a family of up to five.', 'count' => 6, 'group' => 'Family Wing', 'first' => 301, 'base' => 4, 'adults' => 5, 'children' => 3, 'weekday' => '520.00', 'weekend' => '598.00', 'package' => '850.00'],
        ['name' => 'Water Villa', 'description' => 'Over-water villa on the jetty with a private deck.', 'count' => 4, 'group' => 'Jetty', 'first' => 1, 'base' => 2, 'adults' => 3, 'children' => 2, 'weekday' => '950.00', 'weekend' => '1093.00', 'package' => '1500.00'],
    ];

    /**
     * Guests: name, nationality (ISO code), and the city shown on the profile.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const array GUESTS = [
        ['Nurul Aisyah binti Rahman', 'MY', 'Kota Kinabalu'], ['Wong Kah Meng', 'MY', 'Sandakan'], ['Siti Khadijah binti Omar', 'MY', 'Tawau'],
        ['Jeffrey Anak Langat', 'MY', 'Kuching'], ['Priya Nair', 'MY', 'Kuala Lumpur'], ['Muhammad Hafiz bin Ismail', 'MY', 'Petaling Jaya'],
        ['Lee Chong Wei Ming', 'MY', 'Penang'], ['Farah Nabila binti Yusof', 'MY', 'Johor Bahru'], ['Dayang Rosnah', 'MY', 'Semporna'],
        ['Kenneth Tan', 'SG', 'Singapore'], ['Rachel Lim', 'SG', 'Singapore'], ['Muhd Irfan Hakim', 'SG', 'Singapore'], ['Cheryl Ong', 'SG', 'Singapore'],
        ['Li Wei', 'CN', 'Shanghai'], ['Zhang Xiaoyu', 'CN', 'Shenzhen'], ['Chen Mingzhe', 'CN', 'Chengdu'],
        ['Sato Haruka', 'JP', 'Osaka'], ['Takahashi Kenji', 'JP', 'Tokyo'], ['Yamamoto Aiko', 'JP', 'Nagoya'],
        ['Kim Minjun', 'KR', 'Seoul'], ['Park Seoyeon', 'KR', 'Busan'], ['Lee Jihoon', 'KR', 'Incheon'],
        ['Oliver Bennett', 'GB', 'Manchester'], ['Emma Fischer', 'DE', 'Hamburg'], ['Jack Mitchell', 'AU', 'Brisbane'],
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
        $startedAt = microtime(true);
        $property = $this->createProperty();
        $owner = $this->createStaff($property);
        $today = CarbonImmutable::parse(app(PropertyToday::class)->date($property)->toDateString());

        app(OpenBusinessDate::class)($property, $today, $owner);

        $taxes = $this->seedTaxes($property, $owner);
        $items = $this->seedChargeItems($property, $owner);
        $discounts = $this->seedDiscounts($property, $owner);
        $roomTypes = $this->seedRooms($property, $owner);
        $plans = $this->seedRatePlans($property, $owner, $roomTypes, $items, $today);
        $guests = $this->seedGuests($property, $owner);
        $reservations = $this->seedReservations($property, $owner, $roomTypes, $plans, $items, $discounts, $guests, $today);
        $this->seedFolios($property, $owner, $items, $reservations);

        $this->command?->info(self::NAME.' seeded: '.Reservation::query()->where('property_id', $property->id)->count().' reservations, '.count($guests).' guests, '.$taxes.' taxes.');
        $this->printMetrics($property, $startedAt);
        $this->printCredentials($property);
    }

    /**
     * Print what the run created: counts for the property and the time it took.
     */
    private function printMetrics(Property $property, float $startedAt): void
    {
        $units = ReservationUnit::query()->where('property_id', $property->id);
        $confirmedUnitIds = (clone $units)->where('status', UnitStatus::Confirmed)->select('id');
        $planIds = RatePlan::query()->where('property_id', $property->id)->select('id');

        $rows = [
            ['Room types', RoomType::query()->where('property_id', $property->id)->count()],
            ['Rooms', Room::query()->where('property_id', $property->id)->count()],
            ['Rate plans', $planIds->count()],
            ['Rate rows', Rate::query()->whereIn('rate_plan_id', $planIds)->count()],
            ['Taxes enabled', Tax::query()->where('property_id', $property->id)->where('enabled', true)->count()],
            ['Charge items', ChargeItem::query()->where('property_id', $property->id)->count()],
            ['Discounts', Discount::query()->where('property_id', $property->id)->count()],
            ['Staff', User::query()->where('property_id', $property->id)->count()],
            ['Guests', Guest::query()->where('property_id', $property->id)->count()],
            ['Reservations', Reservation::query()->where('property_id', $property->id)->count()],
            ['Units', (clone $units)->count()],
            ['Unit nights', UnitNight::query()->whereIn('reservation_unit_id', (clone $units)->select('id'))->count()],
            ['Folios', Folio::query()->where('property_id', $property->id)->count()],
            ['Folio lines', FolioLine::query()->whereIn('folio_id', Folio::query()->where('property_id', $property->id)->select('id'))->count()],
            ['Payments', Payment::query()->whereIn('folio_id', Folio::query()->where('property_id', $property->id)->select('id'))->count()],
            ['Add-ons', UnitAddOn::query()->whereIn('reservation_unit_id', (clone $units)->select('id'))->count()],
            ['Booked room amount (RM, confirmed units)', number_format((float) UnitNight::query()->whereIn('reservation_unit_id', $confirmedUnitIds)->sum('amount'), 2)],
            ['Seeding time', number_format(microtime(true) - $startedAt, 1).' s'],
        ];

        $this->command?->newLine();
        $this->command?->table(['Metric', 'Value'], $rows);
    }

    /**
     * Print the staff logins. The password is the local development constant of this seeder.
     */
    private function printCredentials(Property $property): void
    {
        $this->command?->newLine();
        $this->command?->info('Sign in at '.url('/login').' (property code '.$property->code.', staff URLs start with '.url('/p/'.$property->code).').');
        $this->command?->table(['Role', 'Name', 'Email', 'Password'], $this->credentials);
    }

    private function createProperty(): Property
    {
        $property = DB::transaction(function (): Property {
            $property = (new Property)->forceFill([
                'code' => app(IssuePropertyCode::class)(),
                'name' => self::NAME,
                'type' => PropertyType::Resort,
                'status' => PropertyStatus::Onboarding,
                'address_line1' => 'Jalan Pantai Bakau',
                'city' => 'Semporna',
                'state_code' => 'Sabah',
                'postal_code' => '91300',
                'country_code' => 'MY',
                'contact_email' => 'hello@sandbay.test',
                'contact_phone' => '+6089781234',
                'onboarding_started_at' => now()->subDays(14),
            ]);
            $property->save();

            return $property;
        });

        app(GenerateDocumentPrefix::class)($property);
        app(SeedPropertyDefaults::class)($property, PropertyType::Resort, 'MY');
        $property->settings()->update(['check_out_time' => '12:00']);
        $property->forceFill(['status' => PropertyStatus::Live, 'went_live_at' => now()])->save();

        return $property->refresh();
    }

    /**
     * Create the four staff accounts and return the Owner.
     */
    private function createStaff(Property $property): User
    {
        $accounts = [
            [DefaultRoleEnum::Owner, 'Siti Aminah Owner', 'owner@sandbay.test'],
            [DefaultRoleEnum::Manager, 'Jason Manager', 'manager@sandbay.test'],
            [DefaultRoleEnum::FrontDesk, 'Farah Front Desk', 'frontdesk@sandbay.test'],
            [DefaultRoleEnum::Housekeeping, 'Mary Housekeeping', 'housekeeping@sandbay.test'],
        ];

        $users = [];

        foreach ($accounts as [$role, $name, $email]) {
            $user = new User(['name' => $name, 'email' => $email, 'password' => self::STAFF_PASSWORD]);
            $user->property_id = $property->id;
            $user->email_verified_at = now();
            $user->save();
            $users[$role->value] = $user;
            $this->credentials[] = [$role->label(), $name, $email, self::STAFF_PASSWORD];
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
     * Enable SST and Tourism Tax, add a 10% service charge, and link all three to the room revenue codes.
     */
    private function seedTaxes(Property $property, User $owner): int
    {
        $sst = Tax::query()->where('property_id', $property->id)->where('code', 'SST')->firstOrFail();
        $tourism = Tax::query()->where('property_id', $property->id)->where('code', 'TTX')->firstOrFail();

        app(UpdateTax::class)($owner, $sst, ['enabled' => true]);
        app(UpdateTax::class)($owner, $tourism, ['enabled' => true]);

        $serviceCharge = app(CreateTax::class)($property, $owner, [
            'name' => 'Service charge',
            'code' => 'SC',
            'rate_type' => TaxRateType::Percent->value,
            'rate' => '10.00',
            'is_inclusive' => false,
            'foreign_guests_only' => false,
            'collect_at_check_in' => false,
            'registration_number' => null,
            'enabled' => true,
        ]);

        app(UpdateRoomRevenueTaxes::class)($property, $owner, [$sst->id, $tourism->id, $serviceCharge->id]);

        return 3;
    }

    /**
     * @return array<string, ChargeItem> Keyed by code.
     */
    private function seedChargeItems(Property $property, User $owner): array
    {
        $definitions = [
            ['Breakfast', 'BREAKFAST', TransactionCategory::FoodAndBeverage, '20.00', ChargingUnit::PerGuestNight],
            ['Lunch', 'LUNCH', TransactionCategory::FoodAndBeverage, '30.00', ChargingUnit::PerGuestNight],
            ['Dinner', 'DINNER', TransactionCategory::FoodAndBeverage, '50.00', ChargingUnit::PerGuestNight],
            ['Jetty fee', 'JETTY_FEE', TransactionCategory::Fee, '10.00', ChargingUnit::PerGuest],
            ['Extra bed', 'EXTRA_BED', TransactionCategory::Fee, '50.00', ChargingUnit::PerNight],
            ['Extra towel', 'EXTRA_TOWEL', TransactionCategory::Fee, '5.00', ChargingUnit::PerItem],
            ['Airport transfer', 'AIRPORT_TRANSFER', TransactionCategory::Fee, '120.00', ChargingUnit::PerStay],
            ['Island hopping trip', 'ISLAND_HOPPING', TransactionCategory::Other, '180.00', ChargingUnit::PerGuest],
            ['Diving trip', 'DIVING_TRIP', TransactionCategory::Other, '280.00', ChargingUnit::PerGuest],
        ];

        $items = [];

        foreach ($definitions as [$name, $code, $category, $amount, $unit]) {
            $item = app(CreateChargeItem::class)($property, $owner, [
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
            $items[$code] = $item;
        }

        return $items;
    }

    /**
     * @return array<string, Discount> Keyed by code.
     */
    private function seedDiscounts(Property $property, User $owner): array
    {
        $discounts = [];

        foreach ([['Early bird', 'EARLY_BIRD', '10.00'], ['Staff rate', 'STAFF_RATE', '30.00']] as [$name, $code, $value]) {
            $discounts[$code] = app(CreateDiscount::class)($property, $owner, [
                'name' => $name,
                'code' => $code,
                'type' => DiscountType::Percent->value,
                'value' => $value,
                'allow_override' => false,
                'scope' => DiscountScope::RoomOnly->value,
                'active' => true,
                'transaction_code_ids' => [],
            ]);
        }

        return $discounts;
    }

    /**
     * Create the room types and their 30 rooms.
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
                'sell_unit' => SellUnit::Room->value,
                'max_adults' => $definition['adults'],
                'max_children' => $definition['children'],
                'base_occupancy' => $definition['base'],
                'smoking_allowed' => false,
                'pets_allowed' => false,
            ]);

            $plan = [];

            for ($index = 0; $index < $definition['count']; $index++) {
                $plan[] = [
                    'number' => $definition['name'] === 'Water Villa' ? 'V'.($definition['first'] + $index) : (string) ($definition['first'] + $index),
                    'group' => $definition['group'],
                ];
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
     * Rename the starter plan to Room Only, add the other four plans, and price the next 200 days.
     *
     * @param  array<string, RoomType>  $roomTypes
     * @param  array<string, ChargeItem>  $items
     * @return array<string, RatePlan> Keyed by name.
     */
    private function seedRatePlans(Property $property, User $owner, array $roomTypes, array $items, CarbonImmutable $today): array
    {
        $roomTypeIds = array_values(array_map(fn (RoomType $roomType): int => $roomType->id, $roomTypes));

        $roomOnly = RatePlan::query()->where('property_id', $property->id)->orderBy('position')->firstOrFail();
        app(UpdateRatePlan::class)($owner, $roomOnly, ['name' => 'Room Only', 'description' => 'Room only, no meals.', 'extra_adult_amount' => '60.00', 'extra_child_amount' => '30.00']);

        $extras = ['extra_adult_amount' => '60.00', 'extra_child_amount' => '30.00', 'pricing_basis' => PricingBasis::PerRoom->value];
        $bedAndBreakfast = app(CreateRatePlan::class)($property, $owner, ['name' => 'Bed & Breakfast', 'description' => 'Breakfast for every guest.', ...$extras], $roomTypeIds);
        $halfBoard = app(CreateRatePlan::class)($property, $owner, ['name' => 'Half Board', 'description' => 'Breakfast and dinner for every guest.', ...$extras], $roomTypeIds);
        $dive = app(CreateRatePlan::class)($property, $owner, [
            'name' => 'Dive Package',
            'description' => 'Three nights with meals and diving trips, priced for each guest.',
            'pricing_basis' => PricingBasis::Package->value,
            'fixed_nights' => 3,
            'package_unit' => 'per_guest',
        ], $roomTypeIds);

        $plans = ['Room Only' => $roomOnly, 'Bed & Breakfast' => $bedAndBreakfast, 'Half Board' => $halfBoard, 'Dive Package' => $dive];

        foreach ([['Room Only', '0.00'], ['Bed & Breakfast', '40.00'], ['Half Board', '150.00']] as [$name, $supplement]) {
            $this->saveRates($owner, $plans[$name], $today, $supplement);

            app(SaveRatePlanStayDiscounts::class)($owner, $plans[$name], [
                ['min_nights' => 3, 'discount_type' => 'percent', 'value' => '10.00', 'from_night' => 1],
                ['min_nights' => 7, 'discount_type' => 'percent', 'value' => '15.00', 'from_night' => 1],
            ]);
        }

        $meals = MealPeriod::query()->where('property_id', $property->id)->pluck('id', 'name');

        $breakfast = $this->inclusion($items['BREAKFAST'], $meals['Breakfast'] ?? null, '20.00', InclusionStart::DayAfterArrival, InclusionEnd::DepartureDay);
        $lunch = $this->inclusion($items['LUNCH'], $meals['Lunch'] ?? null, '30.00', InclusionStart::DayAfterArrival, InclusionEnd::NightBeforeDeparture);
        $dinner = $this->inclusion($items['DINNER'], $meals['Dinner'] ?? null, '50.00', InclusionStart::ArrivalDay, InclusionEnd::NightBeforeDeparture);

        app(SaveRatePlanInclusions::class)($owner, $bedAndBreakfast, [$breakfast]);
        app(SaveRatePlanInclusions::class)($owner, $halfBoard, [$breakfast, $dinner]);
        app(SaveRatePlanInclusions::class)($owner, $dive, [
            $breakfast,
            $lunch,
            $dinner,
            [
                'id' => null,
                'charge_item_id' => $items['DIVING_TRIP']->id,
                'meal_period_id' => null,
                'charging_unit' => ChargingUnit::PerGuest->value,
                'quantity' => 2,
                'allocated_amount' => '180.00',
                'starts_on' => InclusionStart::DayAfterArrival->value,
                'ends_on' => InclusionEnd::NightBeforeDeparture->value,
            ],
        ]);

        $prices = [];

        foreach (self::ROOM_TYPES as $definition) {
            $prices[] = [
                'id' => null,
                'room_type_id' => $roomTypes[$definition['name']]->id,
                'arrival_from' => $today->toDateString(),
                'arrival_to' => $today->addDays(365)->toDateString(),
                'amount' => $definition['package'],
            ];
        }

        if (! app(SavePackagePricing::class)($owner, $dive, $prices, [])) {
            throw new RuntimeException('Could not save the Dive Package prices.');
        }

        $this->seedDayUsePlan($property, $owner, $roomTypeIds, $today);

        return $plans;
    }

    /**
     * A Day use plan: 2 to 6 hours, sold between 08:00 and 18:00, on every room type. Its hour prices are filled the way
     * Auto would: the first block costs 30% of the room's nightly price and each extra hour adds 10% of it (Garden Room: 2h 60.00, +20.00 an hour).
     * Creating the first Day use plan also gives the DAY_USE code the Taxes tab taxes except flat taxes.
     *
     * @param  list<int>  $roomTypeIds
     */
    private function seedDayUsePlan(Property $property, User $owner, array $roomTypeIds, CarbonImmutable $today): void
    {
        $dayUse = app(CreateRatePlan::class)($property, $owner, [
            'name' => 'Day use',
            'description' => 'A room for a few hours in the daytime, sold at the front desk.',
            'pricing_basis' => PricingBasis::DayUse->value,
            'day_use_min_hours' => 2,
            'day_use_max_hours' => 6,
        ], $roomTypeIds);

        if ($dayUse === null || ! app(UpdateRatePlan::class)($owner, $dayUse, ['name' => 'Day use', 'day_use_from' => '08:00', 'day_use_until' => '18:00'])) {
            throw new RuntimeException('Could not create the Day use plan.');
        }

        $roomTypes = [];

        foreach (self::ROOM_TYPES as $definition) {
            $roomType = RoomType::query()->where('property_id', $property->id)->where('name', $definition['name'])->firstOrFail();
            $days = [];

            foreach (range(1, 7) as $weekday) {
                $nightly = in_array($weekday, [5, 6], true) ? $definition['weekend'] : $definition['weekday'];
                $hours = [];

                foreach (range(2, 6) as $count) {
                    $hours[] = ['hours' => $count, 'amount' => bcmul($nightly, (string) (0.3 + ($count - 2) * 0.1), 2)];
                }

                $days[$weekday] = ['amount' => null, 'occupancy' => [], 'hours' => $hours];
            }

            $roomTypes[] = ['room_type_id' => $roomType->id, 'days' => $days];
        }

        if (app(SaveRates::class)($owner, $dayUse, $today->toDateString(), $today->addDays(200)->toDateString(), $roomTypes) === false) {
            throw new RuntimeException('Could not save the Day use prices.');
        }
    }

    /**
     * @return array{id: null, charge_item_id: int, meal_period_id: int|null, charging_unit: string, quantity: int, allocated_amount: string, starts_on: string, ends_on: string}
     */
    private function inclusion(ChargeItem $item, ?int $mealPeriodId, string $amount, InclusionStart $start, InclusionEnd $end): array
    {
        return [
            'id' => null,
            'charge_item_id' => $item->id,
            'meal_period_id' => $mealPeriodId,
            'charging_unit' => ChargingUnit::PerGuestNight->value,
            'quantity' => 1,
            'allocated_amount' => $amount,
            'starts_on' => $start->value,
            'ends_on' => $end->value,
        ];
    }

    /**
     * Price 200 days from today. Friday and Saturday nights cost about 15% more. The supplement is added to every room type.
     */
    private function saveRates(User $owner, RatePlan $plan, CarbonImmutable $today, string $supplement): void
    {
        $roomTypes = [];

        foreach (self::ROOM_TYPES as $definition) {
            $roomType = RoomType::query()->where('property_id', $plan->property_id)->where('name', $definition['name'])->firstOrFail();
            $days = [];

            foreach (range(1, 7) as $weekday) {
                $base = in_array($weekday, [5, 6], true) ? $definition['weekend'] : $definition['weekday'];
                $days[$weekday] = ['amount' => bcadd($base, $supplement, 2), 'occupancy' => []];
            }

            $roomTypes[] = ['room_type_id' => $roomType->id, 'days' => $days];
        }

        $saved = app(SaveRates::class)($owner, $plan, $today->toDateString(), $today->addDays(200)->toDateString(), $roomTypes);

        if ($saved === false) {
            throw new RuntimeException("Could not save rates for {$plan->name}.");
        }
    }

    /**
     * Create the guests: one VIP and one blacklisted.
     *
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
                'phone' => '+6012'.str_pad((string) (3400000 + $index * 137), 7, '0', STR_PAD_LEFT),
                'nationality_code' => $nationality,
                'country_code' => $nationality,
                'city' => $city,
            ]);
        }

        app(SetVip::class)($owner, $guests[3], true);
        app(BlacklistGuest::class)($owner, $guests[12], 'Left without paying the minibar bill.');

        return $guests;
    }

    /**
     * Create about 20 reservations from the business date on: future bookings, a dive group, a walk-in, a no-show and a cancellation.
     *
     * @param  array<string, RoomType>  $roomTypes
     * @param  array<string, RatePlan>  $plans
     * @param  array<string, ChargeItem>  $items
     * @param  array<string, Discount>  $discounts
     * @param  list<Guest>  $guests
     * @return array{walkIn: Reservation, villa: Reservation, anniversary: Reservation, dive: Reservation}
     */
    private function seedReservations(Property $property, User $owner, array $roomTypes, array $plans, array $items, array $discounts, array $guests, CarbonImmutable $today): array
    {
        $rooms = Room::query()->where('property_id', $property->id)->orderBy('position')->get()->groupBy('room_type_id');
        $roomCursor = [];

        $nextRoom = function (RoomType $roomType) use ($rooms, &$roomCursor): int {
            $roomCursor[$roomType->id] = ($roomCursor[$roomType->id] ?? -1) + 1;

            return $rooms[$roomType->id]->values()[$roomCursor[$roomType->id] % $rooms[$roomType->id]->count()]->id;
        };

        $book = function (string $roomTypeName, string $planName, int $startOffset, int $nights, int $adults, int $guestIndex, array $options = []) use ($property, $owner, $roomTypes, $plans, $guests, $today, $nextRoom): Reservation {
            $roomType = $roomTypes[$roomTypeName];
            $unit = new NewUnitData(
                roomTypeId: $roomType->id,
                ratePlanId: $plans[$planName]->id,
                arrivalDate: $today->addDays($startOffset),
                departureDate: $today->addDays($startOffset + $nights),
                adults: $adults,
                discountId: $options['discount'] ?? null,
                addOns: $options['addOns'] ?? [],
            );

            $data = new NewBookingData(
                source: $options['source'] ?? ReservationSource::Direct,
                units: [$unit],
                guests: [new NewBookingGuestData(guestId: $guests[$guestIndex]->id, unitIndex: 0)],
                picks: ($options['assign'] ?? true) ? [new UnitPickData(unitIndex: 0, roomId: $nextRoom($roomType))] : [],
                notes: $options['notes'] ?? null,
            );

            $result = app(CreateBooking::class)($owner, $property, $data);

            if ($result->isRefused()) {
                throw new RuntimeException("Booking for {$guests[$guestIndex]->full_name} was refused: {$result->refusal}.");
            }

            return $result->reservation;
        };

        $addOn = fn (string $code, ?int $quantity = null): NewAddOnData => new NewAddOnData(chargeItemId: $items[$code]->id, quantity: $quantity);

        $book('Garden Room', 'Room Only', 3, 2, 2, 0);
        $book('Garden Room', 'Bed & Breakfast', 5, 3, 2, 1, ['addOns' => [$addOn('JETTY_FEE')]]);
        $anniversary = $book('Sea View Room', 'Bed & Breakfast', 6, 4, 2, 2, ['discount' => $discounts['EARLY_BIRD']->id, 'notes' => 'Anniversary trip. Please add a cake.']);
        $book('Sea View Room', 'Half Board', 8, 3, 2, 3, ['addOns' => [$addOn('AIRPORT_TRANSFER'), $addOn('EXTRA_TOWEL', 2)]]);
        $book('Family Suite', 'Bed & Breakfast', 10, 5, 4, 4, ['addOns' => [$addOn('EXTRA_BED')]]);
        $book('Water Villa', 'Half Board', 12, 3, 2, 9, ['addOns' => [$addOn('ISLAND_HOPPING')]]);
        $book('Garden Room', 'Room Only', 18, 7, 2, 5, ['discount' => $discounts['STAFF_RATE']->id]);
        $book('Sea View Room', 'Dive Package', 20, 3, 2, 13, ['notes' => 'Advanced open water divers.']);
        $book('Garden Room', 'Dive Package', 22, 3, 2, 14);
        $book('Family Suite', 'Half Board', 25, 4, 4, 16, ['assign' => false]);
        $book('Water Villa', 'Bed & Breakfast', 28, 2, 2, 17);
        $book('Sea View Room', 'Room Only', 30, 3, 2, 19, ['assign' => false]);
        $book('Garden Room', 'Half Board', 35, 5, 2, 21);
        $book('Water Villa', 'Room Only', 40, 4, 2, 22, ['discount' => $discounts['EARLY_BIRD']->id]);
        $book('Sea View Room', 'Bed & Breakfast', 45, 6, 2, 23, ['assign' => false]);

        $walkIn = $book('Garden Room', 'Room Only', 0, 1, 2, 24, ['source' => ReservationSource::WalkIn]);
        $villa = $book('Water Villa', 'Half Board', 0, 3, 2, 6);

        $book('Garden Room', 'Bed & Breakfast', 0, 2, 2, 0, ['notes' => 'Arrives today. Left Confirmed to try check-in.']);

        $noShow = $book('Garden Room', 'Room Only', 0, 2, 2, 7);
        $this->forEachUnit($noShow, fn (ReservationUnit $unit): UnitStayResult => app(MarkNoShow::class)($owner, $unit), 'The no-show');

        $cancelled = $book('Sea View Room', 'Bed & Breakfast', 14, 3, 2, 8);
        $this->forEachUnit($cancelled, fn (ReservationUnit $unit): UnitStayResult => app(CancelUnit::class)($owner, $unit), 'The cancellation');

        $this->bookDayUse($property, $owner, $roomTypes['Garden Room'], $guests[10], $today->addDays(3));

        $dive = $this->diveGroup($property, $owner, $roomTypes, $plans, $guests, $today, $nextRoom);

        return ['walkIn' => $walkIn, 'villa' => $villa, 'anniversary' => $anniversary, 'dive' => $dive];
    }

    /**
     * One Confirmed day use: a Garden Room from 10:00 for 4 hours, in the last room that is free for those hours.
     */
    private function bookDayUse(Property $property, User $owner, RoomType $roomType, Guest $guest, CarbonImmutable $date): void
    {
        $plan = RatePlan::query()->where('property_id', $property->id)->where('name', 'Day use')->firstOrFail();
        $room = collect(app(DayUseAvailability::class)->freeRooms($roomType, $date, '10:00', '14:00'))->last()
            ?? throw new RuntimeException('No Garden Room is free for the sample day use.');

        $result = app(CreateBooking::class)($owner, $property, new NewBookingData(
            source: ReservationSource::Phone,
            units: [new NewUnitData(
                roomTypeId: $roomType->id,
                ratePlanId: $plan->id,
                arrivalDate: $date,
                departureDate: $date,
                adults: 2,
                stayType: StayType::DayUse,
                startTime: '10:00',
                hours: 4,
                roomId: $room->id,
            )],
            guests: [new NewBookingGuestData(guestId: $guest->id, unitIndex: 0)],
            picks: [],
            notes: 'Day use between flights.',
        ));

        if ($result->isRefused()) {
            throw new RuntimeException("The sample day use was refused: {$result->refusal}.");
        }
    }

    /**
     * A dive club: four Sea View units on the Dive Package, MasterPays, with the booker and three other guests.
     *
     * @param  array<string, RoomType>  $roomTypes
     * @param  array<string, RatePlan>  $plans
     * @param  list<Guest>  $guests
     */
    private function diveGroup(Property $property, User $owner, array $roomTypes, array $plans, array $guests, CarbonImmutable $today, callable $nextRoom): Reservation
    {
        $roomType = $roomTypes['Sea View Room'];
        $units = [];
        $picks = [];

        foreach (range(0, 3) as $index) {
            $units[] = new NewUnitData(
                roomTypeId: $roomType->id,
                ratePlanId: $plans['Dive Package']->id,
                arrivalDate: $today,
                departureDate: $today->addDays(3),
                adults: 2,
            );
            $picks[] = new UnitPickData(unitIndex: $index, roomId: $nextRoom($roomType));
        }

        $result = app(CreateBooking::class)($owner, $property, new NewBookingData(
            source: ReservationSource::Direct,
            units: $units,
            guests: [
                new NewBookingGuestData(guestId: $guests[10]->id, unitIndex: 0),
                new NewBookingGuestData(guestId: $guests[11]->id, unitIndex: 1),
                new NewBookingGuestData(guestId: $guests[15]->id, unitIndex: 2),
                new NewBookingGuestData(guestId: $guests[18]->id, unitIndex: 3),
            ],
            picks: $picks,
            billingMode: BillingMode::MasterPays,
            groupName: 'Semporna Divers Club',
            notes: 'Dive club trip. Four rooms, one bill.',
        ));

        if ($result->isRefused()) {
            throw new RuntimeException("The dive group was refused: {$result->refusal}.");
        }

        return $result->reservation;
    }

    /**
     * Give the walk-in, the water villa stay and the dive group their folios, and an advance payment to the
     * anniversary booking. Every posting goes through the production Actions, so a refusal stops the run.
     *
     * @param  array<string, ChargeItem>  $items
     * @param  array{walkIn: Reservation, villa: Reservation, anniversary: Reservation, dive: Reservation}  $reservations
     */
    private function seedFolios(Property $property, User $owner, array $items, array $reservations): void
    {
        $methods = PaymentMethod::query()->where('property_id', $property->id)->where('active', true)->get()->keyBy(fn (PaymentMethod $method): string => $method->type->value);
        $cash = $methods[PaymentMethodType::Cash->value];
        $card = $methods[PaymentMethodType::Card->value];

        $this->advance($owner, $reservations['anniversary'], $reservations['anniversary']->units()->firstOrFail(), $card, '300.00');

        // Walk-in: a small bill, paid in full, checked out and closed with an invoice number.
        $walkInUnit = $this->checkIn($owner, $reservations['walkIn'])->first();
        $this->charge($owner, $walkInUnit, $items['EXTRA_TOWEL'], 2);
        $this->charge($owner, $walkInUnit, $items['AIRPORT_TRANSFER']);
        $folio = $this->unitFolio($walkInUnit);
        $this->pay($owner, $reservations['walkIn'], $folio, $cash, PaymentPurpose::Settlement, app(FolioBalance::class)->forFolio($folio));
        $this->checkOut($walkInUnit);
        $closed = app(CloseFolio::class)($owner, $folio);

        if ($closed->isRefused()) {
            throw new RuntimeException("Closing the walk-in folio was refused: {$closed->refusal}.");
        }

        // Water villa: a mistaken charge reversed, a diving trip moved to a third-party folio, a part payment and a refund.
        $villaUnit = $this->checkIn($owner, $reservations['villa'])->first();
        $this->charge($owner, $villaUnit, $items['ISLAND_HOPPING'], 2);
        $mistake = $this->charge($owner, $villaUnit, $items['EXTRA_BED']);
        $reversed = app(ReverseLine::class)($owner, $mistake);

        if ($reversed->isRefused()) {
            throw new RuntimeException("The reversal was refused: {$reversed->refusal}.");
        }

        $diving = $this->charge($owner, $villaUnit, $items['DIVING_TRIP'], 2);
        $payer = $reservations['villa']->booker;
        $thirdParty = app(CreateFolio::class)->thirdParty($reservations['villa'], $payer);
        $transferred = app(TransferLines::class)($owner, [$diving], $thirdParty);

        if ($transferred->isRefused()) {
            throw new RuntimeException("The transfer was refused: {$transferred->refusal}.");
        }

        $villaFolio = $this->unitFolio($villaUnit);
        $payment = $this->pay($owner, $reservations['villa'], $villaFolio, $card, PaymentPurpose::Advance, '400.00');
        $refund = app(RefundPayment::class)($owner, $payment, '50.00', 'Goodwill after a late boat.', (string) Str::uuid());

        if ($refund->isRefused()) {
            throw new RuntimeException("The refund was refused: {$refund->refusal}.");
        }

        // Dive group: arrives today and stays Confirmed, so check-in all can be tried. Diving trips follow a manual rule to the club's third-party folio.
        $clubFolio = app(CreateFolio::class)->thirdParty($reservations['dive'], $reservations['dive']->booker);
        $rule = app(CreateRoutingRule::class)->manual($owner, $reservations['dive'], $clubFolio, null, TransactionCategory::Other);

        if ($rule->isRefused()) {
            throw new RuntimeException("The routing rule was refused: {$rule->refusal}.");
        }

        $this->pay($owner, $reservations['dive'], $clubFolio, $methods[PaymentMethodType::BankTransfer->value], PaymentPurpose::Advance, '1000.00');
    }

    /**
     * Check in every unit of a reservation through `CheckInAllUnits`. A refused unit stops the run.
     *
     * @return Collection<int, ReservationUnit>
     */
    private function checkIn(User $owner, Reservation $reservation): Collection
    {
        $units = $reservation->units()->orderBy('id')->get();
        $refusals = array_filter(app(CheckInAllUnits::class)($owner, $reservation, $units->modelKeys()));

        if ($refusals !== []) {
            throw new RuntimeException('Check-in was refused: '.implode(', ', $refusals).'.');
        }

        return $reservation->units()->orderBy('id')->get();
    }

    /**
     * Run a front desk Action on each unit of a reservation. A refusal stops the run.
     *
     * @param  callable(ReservationUnit): UnitStayResult  $action
     */
    private function forEachUnit(Reservation $reservation, callable $action, string $label): void
    {
        foreach ($reservation->units()->orderBy('id')->get() as $unit) {
            $result = $action($unit);

            if ($result->isRefused()) {
                throw new RuntimeException("{$label} was refused: {$result->refusal}.");
            }
        }
    }

    private function unitFolio(ReservationUnit $unit): Folio
    {
        return Folio::query()->where('reservation_unit_id', $unit->id)->firstOrFail();
    }

    private function checkOut(ReservationUnit $unit): void
    {
        $unit->forceFill(['status' => UnitStatus::CheckedOut, 'checked_out_at' => now()])->save();
        app(SyncReservationStatus::class)($unit->reservation);
    }

    private function charge(User $owner, ReservationUnit $unit, ChargeItem $item, int $quantity = 1): FolioLine
    {
        $result = app(PostCharge::class)($owner, $unit, new NewChargeData(
            chargeItemId: $item->id,
            transactionCodeId: null,
            description: null,
            quantity: $quantity,
            unitAmount: (string) $item->default_amount,
            idempotencyKey: 'manual:'.Str::uuid(),
        ));

        if ($result->isRefused()) {
            throw new RuntimeException("Posting {$item->name} was refused: {$result->refusal}.");
        }

        return $result->lines[0];
    }

    private function advance(User $owner, Reservation $reservation, ReservationUnit $unit, PaymentMethod $method, string $amount): void
    {
        $result = app(RecordPayment::class)($owner, $reservation, null, $unit, new NewPaymentData($method->id, PaymentPurpose::Advance, $amount, 'ADV-'.Str::upper(Str::random(6)), (string) Str::uuid()));

        if ($result->isRefused()) {
            throw new RuntimeException("The advance payment was refused: {$result->refusal}.");
        }
    }

    private function pay(User $owner, Reservation $reservation, Folio $folio, PaymentMethod $method, PaymentPurpose $purpose, string $amount): Payment
    {
        $result = app(RecordPayment::class)($owner, $reservation, $folio, null, new NewPaymentData($method->id, $purpose, $amount, $method->requires_reference ? 'REF-'.Str::upper(Str::random(6)) : null, (string) Str::uuid()));

        if ($result->isRefused()) {
            throw new RuntimeException("The payment was refused: {$result->refusal}.");
        }

        return $result->payment;
    }
}
