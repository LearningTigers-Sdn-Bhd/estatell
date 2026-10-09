<?php

namespace Database\Seeders;

use App\Actions\Catalogues\CreateChargeItem;
use App\Actions\Catalogues\UpdateRoomRevenueTaxes;
use App\Actions\Catalogues\UpdateTax;
use App\Actions\CityLedger\CreateCompany;
use App\Actions\Guests\CreateGuest;
use App\Actions\NightAudit\OpenBusinessDate;
use App\Actions\Property\GenerateDocumentPrefix;
use App\Actions\Property\SeedPropertyDefaults;
use App\Actions\Property\UpdatePropertySettings;
use App\Actions\Rates\CreateDerivedRatePlan;
use App\Actions\Rates\CreateRatePlan;
use App\Actions\Rates\SaveRatePlanInclusions;
use App\Actions\Rates\SaveRates;
use App\Actions\Rates\UpdateRatePlan;
use App\Actions\Rates\UpsertRates;
use App\Actions\Reservations\CreateBooking;
use App\Actions\Rooms\CreateRoomType;
use App\Actions\Rooms\GenerateBeds;
use App\Actions\Rooms\GenerateRooms;
use App\Actions\Staff\AssignOwnerRole;
use App\Actions\Staff\AssignStaffRole;
use App\Data\Reservations\NewBookingData;
use App\Data\Reservations\NewBookingGuestData;
use App\Data\Reservations\NewUnitData;
use App\Enums\BillingMode;
use App\Enums\ChargingUnit;
use App\Enums\CompanyType;
use App\Enums\DefaultRoleEnum;
use App\Enums\InclusionEnd;
use App\Enums\InclusionStart;
use App\Enums\MissedArrivalPolicy;
use App\Enums\PricingBasis;
use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Enums\RateDerivation;
use App\Enums\ReservationSource;
use App\Enums\SellUnit;
use App\Enums\TransactionCategory;
use App\Models\ChargeItem;
use App\Models\Company;
use App\Models\Guest;
use App\Models\MealPeriod;
use App\Models\Property;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Property\PropertyToday;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Seeds Kinabalu Hostel & Suites, a test bench with every single, group and dorm bed booking case. The first business
 * date opens seven days back (D0), and every booking is made on D0, so all arrivals are on or after it. Each
 * reservation's notes start with its scenario code, for example `[G4]`. It runs on demand
 * (`php artisan db:seed --class=BookingScenariosSeeder`), uses the production Actions, and skips when the property
 * exists. See docs/12-reservations/07-booking-scenarios-seeder.md.
 */
class BookingScenariosSeeder extends Seeder
{
    public const string NAME = 'Kinabalu Hostel & Suites';

    /**
     * How many days before today the first business date opens.
     */
    public const int DAYS_BACK = 7;

    /**
     * Shared by every staff account. For local development only.
     */
    private const string STAFF_PASSWORD = '12345678';

    /**
     * Room types: name, sell unit, rooms (number and group), occupancy, and the weekday and weekend Room Only price.
     *
     * @var list<array{name: string, description: string, sell_unit: SellUnit, numbers: list<string>, group: string, base: int, adults: int, children: int, weekday: string, weekend: string}>
     */
    private const array ROOM_TYPES = [
        ['name' => 'Standard Double', 'description' => 'Double room on the first floor.', 'sell_unit' => SellUnit::Room, 'numbers' => ['101', '102', '103', '104', '105', '106', '107', '108', '109', '110'], 'group' => 'First Floor', 'base' => 2, 'adults' => 3, 'children' => 1, 'weekday' => '150.00', 'weekend' => '172.00'],
        ['name' => 'Deluxe Twin', 'description' => 'Twin room with a view of Mount Kinabalu.', 'sell_unit' => SellUnit::Room, 'numbers' => ['201', '202', '203', '204', '205', '206', '207', '208'], 'group' => 'Second Floor', 'base' => 2, 'adults' => 3, 'children' => 1, 'weekday' => '210.00', 'weekend' => '241.00'],
        ['name' => 'Family Suite', 'description' => 'Two bedrooms for a family of up to six.', 'sell_unit' => SellUnit::Room, 'numbers' => ['301', '302'], 'group' => 'Third Floor', 'base' => 4, 'adults' => 5, 'children' => 3, 'weekday' => '380.00', 'weekend' => '437.00'],
        ['name' => '6-Bed Dorm', 'description' => 'Mixed dorm with six bunk beds and lockers.', 'sell_unit' => SellUnit::Bed, 'numbers' => ['D1'], 'group' => 'Dorm', 'base' => 1, 'adults' => 1, 'children' => 0, 'weekday' => '45.00', 'weekend' => '52.00'],
    ];

    /**
     * Guests: name and nationality (ISO code). Every unit gets its own guest, so check-in finds a nationality.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const array GUESTS = [
        ['Ahmad Faizal bin Hassan', 'MY'], ['Tan Mei Ling', 'MY'], ['Rajesh Kumar', 'MY'], ['Nur Izzati binti Azman', 'MY'],
        ['Chong Wei Jie', 'MY'], ['Siti Hajar binti Ali', 'MY'], ['Vincent Majalap', 'MY'], ['Lim Jia Hui', 'MY'],
        ['Mohd Aiman bin Rosli', 'MY'], ['Kavitha Subramaniam', 'MY'], ['Ong Kok Leong', 'MY'], ['Azlina binti Jaafar', 'MY'],
        ['Rosmah Gimbang', 'MY'], ['Daniel Liew', 'MY'], ['Hidayah binti Salleh', 'MY'], ['Benedict Sipaun', 'MY'],
        ['Goh Siew Lan', 'MY'], ['Arif Danial bin Zainal', 'MY'], ['Melissa Yap', 'MY'], ['Suresh Pillai', 'MY'],
        ['Kenneth Teo', 'SG'], ['Grace Tan', 'SG'], ['Hafiz Rahmat', 'SG'], ['Joanne Koh', 'SG'],
        ['Wang Lei', 'CN'], ['Liu Yang', 'CN'], ['Zhao Min', 'CN'], ['Huang Jing', 'CN'],
        ['Suzuki Ren', 'JP'], ['Ito Yui', 'JP'], ['Nakamura Sho', 'JP'], ['Kobayashi Mio', 'JP'],
        ['Choi Yuna', 'KR'], ['Jung Hoseok', 'KR'], ['Kang Dahye', 'KR'], ['Yoon Jisung', 'KR'],
        ['Thomas Wright', 'GB'], ['Sophie Hughes', 'GB'], ['Harry Evans', 'GB'], ['Chloe Turner', 'GB'],
        ['Lukas Becker', 'DE'], ['Hannah Wagner', 'DE'], ['Felix Schulz', 'DE'], ['Lea Hoffmann', 'DE'],
        ['Liam O\'Connor', 'AU'], ['Mia Robinson', 'AU'], ['Noah Clarke', 'AU'], ['Ava Thompson', 'AU'],
        ['Lucas Martin', 'FR'], ['Camille Dubois', 'FR'], ['Hugo Bernard', 'FR'], ['Manon Petit', 'FR'],
        ['Budi Santoso', 'ID'], ['Dewi Lestari', 'ID'], ['Agus Wijaya', 'ID'], ['Putri Maharani', 'ID'],
        ['Nguyen Van Minh', 'VN'], ['Tran Thi Lan', 'VN'], ['Somchai Prasert', 'TH'], ['Nattaya Srisuk', 'TH'],
        ['Juan dela Cruz', 'PH'], ['Maria Santos', 'PH'], ['Emily Johnson', 'US'], ['Michael Brown', 'US'],
    ];

    /**
     * The staff accounts created by this run, for the table printed at the end.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private array $credentials = [];

    /**
     * @var array<string, Reservation> Keyed by scenario code.
     */
    private array $reservations = [];

    /**
     * @var list<Guest>
     */
    private array $guests = [];

    private int $guestCursor = 0;

    private Property $property;

    private User $owner;

    private CarbonImmutable $firstDate;

    /**
     * @var array<string, RoomType> Keyed by name.
     */
    private array $roomTypes = [];

    /**
     * @var array<string, RatePlan> Keyed by name.
     */
    private array $plans = [];

    /**
     * @var array<string, ChargeItem> Keyed by code.
     */
    private array $items = [];

    private Company $company;

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
        $this->property = $this->createProperty();
        $this->owner = $this->createStaff();
        $today = CarbonImmutable::parse(app(PropertyToday::class)->date($this->property)->toDateString());
        $this->firstDate = $today->subDays(self::DAYS_BACK);

        app(OpenBusinessDate::class)($this->property, $this->firstDate, $this->owner);

        $this->seedTaxes();
        $this->seedChargeItems();
        $this->seedRooms();
        $this->seedRatePlans();
        $this->seedCompany();
        $this->seedGuests();
        $this->seedSingles();
        $this->seedGroups();
        $this->seedDormBeds();

        $this->printScenarios();
        $this->printCredentials();
    }

    private function createProperty(): Property
    {
        $property = Property::query()->create([
            'name' => self::NAME,
            'type' => PropertyType::Hostel,
            'status' => PropertyStatus::Onboarding,
            'address_line1' => 'Jalan Gaya',
            'city' => 'Kota Kinabalu',
            'state_code' => 'Sabah',
            'postal_code' => '88000',
            'country_code' => 'MY',
            'contact_email' => 'hello@kinabalu-hostel.test',
            'contact_phone' => '+6088251234',
            'onboarding_started_at' => now()->subDays(30),
        ]);

        app(GenerateDocumentPrefix::class)($property);
        app(SeedPropertyDefaults::class)($property, PropertyType::Hostel, 'MY');
        app(UpdatePropertySettings::class)($property, [
            'check_out_time' => '12:00',
            'auto_assign_rooms' => true,
            'missed_arrival_policy' => MissedArrivalPolicy::MarkNoShow->value,
        ]);
        $property->forceFill(['status' => PropertyStatus::Live, 'went_live_at' => now()])->save();

        return $property->refresh();
    }

    /**
     * Create the Owner, Manager and Front desk accounts and return the Owner.
     */
    private function createStaff(): User
    {
        $accounts = [
            [DefaultRoleEnum::Owner, 'Rahim Owner', 'owner@kinabalu-hostel.test'],
            [DefaultRoleEnum::Manager, 'Angela Manager', 'manager@kinabalu-hostel.test'],
            [DefaultRoleEnum::FrontDesk, 'Joseph Front Desk', 'frontdesk@kinabalu-hostel.test'],
        ];

        $users = [];

        foreach ($accounts as [$role, $name, $email]) {
            $user = new User(['name' => $name, 'email' => $email, 'password' => self::STAFF_PASSWORD]);
            $user->property_id = $this->property->id;
            $user->email_verified_at = now();
            $user->save();
            $users[$role->value] = $user;
            $this->credentials[] = [$role->label(), $name, $email, self::STAFF_PASSWORD];
        }

        $owner = $users[DefaultRoleEnum::Owner->value];
        app(AssignOwnerRole::class)($this->property, $owner);

        foreach ([DefaultRoleEnum::Manager, DefaultRoleEnum::FrontDesk] as $role) {
            $roleModel = Role::query()->where('property_id', $this->property->id)->where('default_role', $role)->firstOrFail();

            if (! app(AssignStaffRole::class)($owner, $users[$role->value], $roleModel)) {
                throw new RuntimeException("Could not assign the {$role->value} role.");
            }
        }

        return $owner;
    }

    /**
     * Enable SST and Tourism Tax and link both to the room revenue codes.
     */
    private function seedTaxes(): void
    {
        $taxes = $this->property->taxes()->whereIn('code', ['SST', 'TTX'])->get();

        foreach ($taxes as $tax) {
            app(UpdateTax::class)($this->owner, $tax, ['enabled' => true]);
        }

        app(UpdateRoomRevenueTaxes::class)($this->property, $this->owner, $taxes->modelKeys());
    }

    private function seedChargeItems(): void
    {
        $definitions = [
            ['Breakfast', 'BREAKFAST', TransactionCategory::FoodAndBeverage, '18.00', ChargingUnit::PerGuestNight],
            ['Laundry bag', 'LAUNDRY_BAG', TransactionCategory::Other, '25.00', ChargingUnit::PerItem],
        ];

        foreach ($definitions as [$name, $code, $category, $amount, $unit]) {
            $this->items[$code] = app(CreateChargeItem::class)($this->property, $this->owner, [
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
    }

    /**
     * Create the room types, their 21 rooms and the six dorm beds.
     */
    private function seedRooms(): void
    {
        foreach (self::ROOM_TYPES as $definition) {
            $roomType = app(CreateRoomType::class)($this->property, $this->owner, [
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
            $result = app(GenerateRooms::class)($this->property, $this->owner, $roomType, null, $plan);

            if ($result['conflicts'] !== []) {
                throw new RuntimeException('Room numbers already taken: '.implode(', ', $result['conflicts']));
            }

            if ($definition['sell_unit'] === SellUnit::Bed) {
                $roomIds = Room::query()->where('room_type_id', $roomType->id)->pluck('id')->all();

                if (app(GenerateBeds::class)($this->property, $this->owner, $roomIds, 6) !== 6) {
                    throw new RuntimeException('Could not create the dorm beds.');
                }
            }

            $this->roomTypes[$definition['name']] = $roomType;
        }
    }

    /**
     * Room Only and Bed & Breakfast for the rooms, Dorm Bed (priced per bed) for the dorm, and later Corporate (10% below
     * Room Only, for the company). Rates run from D0 for 60 days. Standard Double on Room Only needs three nights for an arrival on D15 (S5).
     */
    private function seedRatePlans(): void
    {
        $roomOnly = RatePlan::query()->where('property_id', $this->property->id)->orderBy('position')->firstOrFail();
        app(UpdateRatePlan::class)($this->owner, $roomOnly, ['name' => 'Room Only', 'description' => 'Room only, no meals.', 'extra_adult_amount' => '50.00', 'extra_child_amount' => '25.00']);

        $roomTypeIds = array_map(fn (string $name): int => $this->roomTypes[$name]->id, ['Standard Double', 'Deluxe Twin', 'Family Suite']);
        $bedAndBreakfast = app(CreateRatePlan::class)($this->property, $this->owner, [
            'name' => 'Bed & Breakfast',
            'description' => 'Breakfast for every guest.',
            'extra_adult_amount' => '50.00',
            'extra_child_amount' => '25.00',
            'pricing_basis' => PricingBasis::PerRoom->value,
        ], $roomTypeIds);

        $dormBed = app(CreateRatePlan::class)($this->property, $this->owner, [
            'name' => 'Dorm Bed',
            'description' => 'One bed in the dorm, no meals.',
            'pricing_basis' => PricingBasis::PerBed->value,
        ], [$this->roomTypes['6-Bed Dorm']->id]);

        $this->plans = ['Room Only' => $roomOnly->refresh(), 'Bed & Breakfast' => $bedAndBreakfast, 'Dorm Bed' => $dormBed];

        $this->saveRates($roomOnly, '0.00', ['Standard Double', 'Deluxe Twin', 'Family Suite']);
        $this->saveRates($dormBed, '0.00', ['6-Bed Dorm']);
        $this->saveRates($bedAndBreakfast, '36.00', ['Standard Double', 'Deluxe Twin', 'Family Suite']);

        $breakfast = MealPeriod::query()->where('property_id', $this->property->id)->where('name', 'Breakfast')->value('id');
        app(SaveRatePlanInclusions::class)($this->owner, $bedAndBreakfast, [[
            'id' => null,
            'charge_item_id' => $this->items['BREAKFAST']->id,
            'meal_period_id' => $breakfast,
            'charging_unit' => ChargingUnit::PerGuestNight->value,
            'quantity' => 1,
            'allocated_amount' => '18.00',
            'starts_on' => InclusionStart::DayAfterArrival->value,
            'ends_on' => InclusionEnd::DepartureDay->value,
        ]]);

        $this->restrictMinimumStay($roomOnly, $this->roomTypes['Standard Double'], $this->firstDate->addDays(15), 3);
    }

    /**
     * Price the room types from D0 for 60 days. Friday and Saturday nights cost about 15% more.
     *
     * @param  list<string>  $roomTypeNames
     */
    private function saveRates(RatePlan $plan, string $supplement, array $roomTypeNames): void
    {
        $rows = [];

        foreach (self::ROOM_TYPES as $definition) {
            if (! in_array($definition['name'], $roomTypeNames, true)) {
                continue;
            }

            $days = [];

            foreach (range(1, 7) as $weekday) {
                $base = in_array($weekday, [5, 6], true) ? $definition['weekend'] : $definition['weekday'];
                $days[$weekday] = ['amount' => bcadd($base, $supplement, 2), 'occupancy' => []];
            }

            $rows[] = ['room_type_id' => $this->roomTypes[$definition['name']]->id, 'days' => $days];
        }

        $saved = app(SaveRates::class)($this->owner, $plan, $this->firstDate->toDateString(), $this->firstDate->addDays(60)->toDateString(), $rows);

        if ($saved === false) {
            throw new RuntimeException("Could not save rates for {$plan->name}.");
        }
    }

    private function restrictMinimumStay(RatePlan $plan, RoomType $roomType, CarbonImmutable $date, int $nights): void
    {
        $rate = Rate::query()->where('rate_plan_id', $plan->id)->where('room_type_id', $roomType->id)->whereDate('date', $date)->firstOrFail();

        app(UpsertRates::class)($plan->id, [[
            'room_type_id' => $roomType->id,
            'date' => $date->toDateString(),
            'amount' => (string) $rate->amount,
            'min_stay' => $nights,
            'max_stay' => $rate->max_stay,
            'closed_to_arrival' => (bool) $rate->closed_to_arrival,
            'closed_to_departure' => (bool) $rate->closed_to_departure,
            'stop_sell' => (bool) $rate->stop_sell,
        ]]);
    }

    /**
     * Borneo Ventures, with its own Corporate plan 10% below Room Only.
     */
    private function seedCompany(): void
    {
        $this->company = app(CreateCompany::class)($this->owner, $this->property, [
            'name' => 'Borneo Ventures Sdn Bhd',
            'type' => CompanyType::Corporate,
            'contact_email' => 'travel@borneo-ventures.test',
            'credit_limit' => null,
            'payment_terms_days' => 30,
            'direct_bill_enabled' => true,
        ]);

        $corporate = app(CreateDerivedRatePlan::class)(
            $this->owner,
            $this->plans['Room Only'],
            'Corporate',
            RateDerivation::Percent,
            '-10',
            [$this->roomTypes['Standard Double']->id, $this->roomTypes['Deluxe Twin']->id],
            [$this->company->id],
        );

        if ($corporate === null) {
            throw new RuntimeException('Could not create the Corporate plan.');
        }

        $this->plans['Corporate'] = $corporate;
    }

    private function seedGuests(): void
    {
        foreach (self::GUESTS as $index => [$name, $nationality]) {
            $slug = trim(strtolower(preg_replace('/[^a-z]+/i', '.', $name) ?? 'guest'), '.');
            $this->guests[] = app(CreateGuest::class)($this->owner, $this->property, [
                'full_name' => $name,
                'email' => $slug.'@guests.test',
                'phone' => '+6013'.str_pad((string) (5200000 + $index * 151), 7, '0', STR_PAD_LEFT),
                'nationality_code' => $nationality,
                'country_code' => $nationality,
            ]);
        }
    }

    /**
     * S1 to S14. See the Single bookings table in the doc.
     */
    private function seedSingles(): void
    {
        $this->book('S1', 'Plain stay, finished.', [$this->line('Standard Double', 'Room Only', 0, 2, 2)]);
        $this->book('S2', 'Family with children aged 4, 9 and 17.', [$this->line('Family Suite', 'Bed & Breakfast', 9, 12, 2, childAges: [4, 9, 17])]);
        $this->book('S3', 'Complimentary stay.', [$this->line('Standard Double', 'Room Only', 8, 9, 2, overrideTotal: '0.00', overrideReason: 'Complimentary night for a travel blogger.')]);
        $this->book('S4', 'Price override with a remainder.', [$this->line('Standard Double', 'Room Only', 9, 12, 2, overrideTotal: '100.00', overrideReason: 'Long-stay regular, agreed price.')]);
        $this->book('S5', 'One night against a three-night minimum stay.', [$this->line('Standard Double', 'Room Only', 15, 16, 2, restrictionOverrideReason: 'Owner approved one night for a returning guest.')]);
        $this->book('S6', 'OTA booking. With S2, the Family Suites are sold out.', [$this->line('Family Suite', 'Room Only', 9, 12, 4)], [
            'source' => ReservationSource::Channel,
            'channelName' => 'Booking.com',
            'externalReference' => 'BDC-4417829356',
            'fromChannel' => true,
        ]);
        $this->book('S7', 'Company single on the Corporate plan. Arrives today.', [$this->line('Deluxe Twin', 'Corporate', 7, 9, 1)], ['companyId' => $this->company->id]);
        $this->book('S8', 'Moves room from night D6.', [$this->line('Standard Double', 'Bed & Breakfast', 4, 9, 2)]);
        $this->book('S9', 'Stay extended by two nights.', [$this->line('Deluxe Twin', 'Room Only', 3, 6, 2)]);
        $this->book('S10', 'Leaves three nights early.', [$this->line('Standard Double', 'Room Only', 2, 7, 1)]);
        $this->book('S11', 'Departs today with a balance due.', [$this->line('Standard Double', 'Bed & Breakfast', 5, 7, 2)]);
        $this->book('S12', 'Cancelled, then reinstated.', [$this->line('Standard Double', 'Room Only', 20, 22, 2)]);
        $this->book('S13', 'No room assigned. Arrives today.', [$this->line('Standard Double', 'Room Only', 7, 9, 2)]);
        $this->book('S14', 'Late arrival expected. Kept by the audit.', [$this->line('Standard Double', 'Room Only', 6, 8, 2, lateArrivalExpected: true)], ['source' => ReservationSource::Phone]);
    }

    /**
     * G1 to G10. See the Group bookings table in the doc.
     */
    private function seedGroups(): void
    {
        $this->book('G1', 'Wedding party with staggered arrivals.', [
            ...$this->lines(2, 'Standard Double', 'Bed & Breakfast', 6, 9, 2),
            ...$this->lines(3, 'Deluxe Twin', 'Bed & Breakfast', 8, 10, 2),
        ], ['groupName' => 'Lee and Aisyah Wedding']);

        $this->book('G2', 'Corporate team, the company pays everything.', $this->lines(4, 'Deluxe Twin', 'Corporate', 5, 9, 1), [
            'billingMode' => BillingMode::MasterPaysAll,
            'companyId' => $this->company->id,
            'groupName' => 'Borneo Ventures Sales Retreat',
        ]);

        $this->book('G3', 'Friends trip, each room pays its own bill.', $this->lines(3, 'Standard Double', 'Room Only', 6, 8, 2), [
            'billingMode' => BillingMode::EachPays,
            'groupName' => 'KK Hiking Club',
        ]);

        $this->book('G4', 'Partly cancelled group.', $this->lines(4, 'Standard Double', 'Room Only', 12, 14, 2), ['groupName' => 'Sabah Teachers Union']);
        $this->book('G5', 'Fully cancelled group.', $this->lines(2, 'Standard Double', 'Room Only', 18, 20, 2), ['billingMode' => BillingMode::EachPays, 'groupName' => 'Tawau Badminton Team']);
        $this->book('G6', 'One room cancelled, the other a no-show.', $this->lines(2, 'Standard Double', 'Room Only', 4, 6, 2), ['billingMode' => BillingMode::EachPays, 'groupName' => 'Penang Cousins']);

        $this->book('G7', 'One room came and left, two still to arrive.', [
            $this->line('Deluxe Twin', 'Room Only', 1, 3, 2),
            ...$this->lines(2, 'Deluxe Twin', 'Room Only', 9, 11, 2),
        ], ['groupName' => 'Mount Kinabalu Climbers']);

        $this->book('G8', 'The Master folio holds an advance, so the billing mode is locked.', $this->lines(2, 'Deluxe Twin', 'Room Only', 10, 12, 2), ['groupName' => 'Sandakan Photo Society']);
        $this->book('G9', 'A third room is added later.', $this->lines(2, 'Standard Double', 'Room Only', 10, 13, 2), ['groupName' => 'Labuan Ferry Crew']);
        $this->book('G10', 'No group name, so the booker names it.', $this->lines(2, 'Deluxe Twin', 'Bed & Breakfast', 14, 16, 2), ['billingMode' => BillingMode::EachPays]);
    }

    /**
     * B1 to B4. See the Dorm beds table in the doc.
     */
    private function seedDormBeds(): void
    {
        $this->book('B1', 'Solo backpacker.', [$this->line('6-Bed Dorm', 'Dorm Bed', 5, 9, 1)]);
        $this->book('B2', 'Four backpackers, each bed on its own bill.', [$this->line('6-Bed Dorm', 'Dorm Bed', 3, 9, 1, beds: 4)], ['billingMode' => BillingMode::EachPays, 'groupName' => 'Euro Backpackers']);
        $this->book('B3', 'Two rooms and three dorm beds in one booking.', [
            ...$this->lines(2, 'Standard Double', 'Room Only', 10, 12, 2),
            $this->line('6-Bed Dorm', 'Dorm Bed', 10, 12, 1, beds: 3),
        ], ['billingMode' => BillingMode::EachPays, 'groupName' => 'University Field Trip']);
        $this->book('B4', 'The whole dorm for one night.', [$this->line('6-Bed Dorm', 'Dorm Bed', 16, 17, 1, beds: 6)], ['groupName' => 'Scout Troop 12']);
    }

    /**
     * One line of a booking. Days are offsets from D0.
     *
     * @param  mixed  ...$options  Other `NewUnitData` arguments, by name.
     */
    private function line(string $roomType, string $plan, int $from, int $to, int $adults, mixed ...$options): NewUnitData
    {
        return new NewUnitData(...[
            'roomTypeId' => $this->roomTypes[$roomType]->id,
            'ratePlanId' => $this->plans[$plan]->id,
            'arrivalDate' => $this->firstDate->addDays($from),
            'departureDate' => $this->firstDate->addDays($to),
            'adults' => $adults,
            ...$options,
        ]);
    }

    /**
     * @return list<NewUnitData>
     */
    private function lines(int $count, string $roomType, string $plan, int $from, int $to, int $adults): array
    {
        return array_map(fn (): NewUnitData => $this->line($roomType, $plan, $from, $to, $adults), range(1, $count));
    }

    /**
     * Book one scenario through `CreateBooking` with a new guest on every unit. The first guest is the booker.
     * No room is picked: check-in assigns one (`auto_assign_rooms`).
     *
     * @param  list<NewUnitData>  $lines
     * @param  array{source?: ReservationSource, billingMode?: BillingMode, groupName?: string, companyId?: int, channelName?: string, externalReference?: string, fromChannel?: bool}  $options
     */
    private function book(string $code, string $note, array $lines, array $options = []): Reservation
    {
        $units = array_sum(array_map(fn (NewUnitData $line): int => $line->beds, $lines));
        $guests = array_map(fn (int $index): NewBookingGuestData => new NewBookingGuestData(guestId: $this->nextGuest()->id, unitIndex: $index), range(0, $units - 1));

        $result = app(CreateBooking::class)($this->owner, $this->property, new NewBookingData(
            source: $options['source'] ?? ReservationSource::Direct,
            units: $lines,
            guests: $guests,
            billingMode: $options['billingMode'] ?? BillingMode::MasterPays,
            channelName: $options['channelName'] ?? null,
            externalReference: $options['externalReference'] ?? null,
            groupName: $options['groupName'] ?? null,
            notes: "[{$code}] {$note}",
            companyId: $options['companyId'] ?? null,
        ), $options['fromChannel'] ?? false);

        if ($result->isRefused()) {
            throw new RuntimeException("Scenario {$code} was refused: {$result->refusal} (line {$result->lineIndex}).");
        }

        return $this->reservations[$code] = $result->reservation;
    }

    private function nextGuest(): Guest
    {
        return $this->guests[$this->guestCursor++] ?? throw new RuntimeException('The seeder ran out of guests.');
    }

    private function printScenarios(): void
    {
        $rows = [];

        foreach ($this->reservations as $code => $reservation) {
            $reservation->refresh();
            $rows[] = [$code, $reservation->reference, $reservation->units()->count(), $reservation->status->label()];
        }

        $this->command?->info(self::NAME.' seeded: '.count($rows).' scenarios, business date '.$this->firstDate->toDateString().'.');
        $this->command?->table(['Code', 'Reference', 'Units', 'Status'], $rows);
    }

    /**
     * Print the staff logins. The password is the local development constant of this seeder.
     */
    private function printCredentials(): void
    {
        $this->command?->newLine();
        $this->command?->info('Sign in at '.url('/login').' (staff URLs start with '.url('/p/'.$this->property->code).').');
        $this->command?->table(['Role', 'Name', 'Email', 'Password'], $this->credentials);
    }
}
