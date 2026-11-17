<?php

namespace Database\Seeders;

use App\Actions\BookingEngine\SaveBookingWebsiteGeneral;
use App\Actions\BookingEngine\SetPropertyAmenity;
use App\Actions\BookingEngine\SetRatePlanBookableOnline;
use App\Actions\BookingEngine\SetUpBookingSite;
use App\Actions\Catalogues\CreateChargeItem;
use App\Actions\Catalogues\CreateDiscount;
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
use App\Actions\Rates\CreateRatePlan;
use App\Actions\Rates\SaveRatePlanStayDiscounts;
use App\Actions\Rates\SaveRates;
use App\Actions\Rates\UpdateRatePlan;
use App\Actions\Reservations\CreateBooking;
use App\Actions\Rooms\CreateRoomBlock;
use App\Actions\Rooms\CreateRoomType;
use App\Actions\Rooms\GenerateBeds;
use App\Actions\Rooms\GenerateRooms;
use App\Actions\Rooms\RoomBlockRefused;
use App\Actions\Rooms\SetRoomTypeAmenity;
use App\Actions\Staff\AssignOwnerRole;
use App\Actions\Staff\AssignStaffRole;
use App\Data\Catalogues\TaxInputData;
use App\Data\CityLedger\NewArPaymentData;
use App\Data\Reservations\NewBookingData;
use App\Data\Reservations\NewBookingGuestData;
use App\Data\Reservations\NewUnitData;
use App\Data\Reservations\UnitPickData;
use App\Data\Rooms\RoomBlockInputData;
use App\Enums\AmenityType;
use App\Enums\BillingMode;
use App\Enums\BookableOnlineResult;
use App\Enums\ChargingUnit;
use App\Enums\CompanyType;
use App\Enums\DefaultRoleEnum;
use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Enums\FolioStatus;
use App\Enums\HousekeepingStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Enums\PricingBasis;
use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Enums\RoomBlockReason;
use App\Enums\SellUnit;
use App\Enums\StayDiscountType;
use App\Enums\TransactionCategory;
use App\Features\BedRoomTypes;
use App\Features\BookingEngine;
use App\Models\Amenity;
use App\Models\ArInvoice;
use App\Models\Bed;
use App\Models\BookingSource;
use App\Models\ChargeItem;
use App\Models\Company;
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
 * Seeds Varsity Loft in production, for sales pitches: a student hostel next to Universiti Malaysia Sabah in Kota
 * Kinabalu. It sells beds in shared rooms (a male floor, a female floor and twin sharing) and whole rooms (single
 * study rooms and studios). A live property with an open business date, per-bed and per-room plans with long-stay
 * discounts, students and about 16 reservations dated relative to the business date: semester stays, move-ins today,
 * a university intake group, a sponsored student billed to the city ledger, short stays, a no-show, a cancellation and
 * a blocked bed. The booking engine is on in Microsite mode with the About text, location and amenities set, but not
 * published: it needs photos first. Room charges are not seeded: they post at the night audit, and the seeder runs none. Every step uses
 * the production Actions. Staff sign in as `owner@varsityloft.com` (and manager, frontdesk, housekeeping),
 * with `BORENO_SEED_PASSWORD` (the internal pitch password, shared with Boreno). It is never part of the demo. See docs/deployment/production_environment.md.
 */
class VarsityLoftSeeder extends Seeder
{
    use SeedsThroughActions;

    private const string NAME = 'Varsity Loft';

    /**
     * Room types: name, sell unit, room numbers, beds per room (bed types only), group, occupancy and the nightly
     * price (per bed for a bed type). Students pay the same every night of the week.
     *
     * @var list<array{name: string, description: string, sell_unit: SellUnit, numbers: list<string>, beds: int, group: string, base: int, adults: int, price: string}>
     */
    private const array ROOM_TYPES = [
        ['name' => '4-Bed Male Room', 'description' => 'Four single beds on the male floor, a study desk and a wardrobe per bed, and a shared bathroom.', 'sell_unit' => SellUnit::Bed, 'numbers' => ['101', '102', '103', '104'], 'beds' => 4, 'group' => 'Level 1 · Male', 'base' => 1, 'adults' => 1, 'price' => '30.00'],
        ['name' => '4-Bed Female Room', 'description' => 'Four single beds on the female-only floor, with key card access.', 'sell_unit' => SellUnit::Bed, 'numbers' => ['201', '202', '203', '204'], 'beds' => 4, 'group' => 'Level 2 · Female', 'base' => 1, 'adults' => 1, 'price' => '32.00'],
        ['name' => 'Twin Sharing', 'description' => 'Two single beds and two study desks, with its own bathroom.', 'sell_unit' => SellUnit::Bed, 'numbers' => ['301', '302', '303', '304'], 'beds' => 2, 'group' => 'Level 3', 'base' => 1, 'adults' => 1, 'price' => '45.00'],
        ['name' => 'Single Study Room', 'description' => 'A single bed, a large desk and air conditioning. Shared bathroom.', 'sell_unit' => SellUnit::Room, 'numbers' => ['305', '306', '307', '308', '309', '310'], 'beds' => 0, 'group' => 'Level 3', 'base' => 1, 'adults' => 1, 'price' => '75.00'],
        ['name' => 'Studio', 'description' => 'A queen bed, a kitchenette and a bathroom. For postgraduates and visiting parents.', 'sell_unit' => SellUnit::Room, 'numbers' => ['401', '402'], 'beds' => 0, 'group' => 'Level 4', 'base' => 2, 'adults' => 2, 'price' => '120.00'],
    ];

    /**
     * Long-stay discounts on both plans: a month and a semester.
     *
     * @var list<array{min_nights: int, discount_type: string, value: string, from_night: int}>
     */
    private const array STAY_DISCOUNTS = [
        ['min_nights' => 28, 'discount_type' => StayDiscountType::Percent->value, 'value' => '15.00', 'from_night' => 1],
        ['min_nights' => 90, 'discount_type' => StayDiscountType::Percent->value, 'value' => '25.00', 'from_night' => 1],
    ];

    /**
     * Property amenities, by name, from the platform amenity list.
     *
     * @var list<string>
     */
    private const array PROPERTY_AMENITIES = [
        'Wi-Fi', 'Free Wi-Fi in public areas', 'Air conditioning', 'Lift', 'Female-only floors', 'Non-smoking throughout',
        'Surau (prayer room)', 'Co-working space', 'Free parking', 'Bike parking', 'Self-service laundry',
        'Shuttle service (paid)', 'Baggage storage', 'Library', 'Shared lounge', 'Cashless payment', 'Shared kitchen',
        'Drinking water', 'Vending machine', 'Halal food', '24-hour security', 'CCTV in common areas', 'Key card access',
        'Lockers', 'Fire extinguishers', 'Smoke alarms', 'First aid kit', 'Table tennis', 'Board games and puzzles',
        'Refillable water stations', 'Energy-efficient lighting',
    ];

    /**
     * Room amenities, by name, for every room type, then the ones only some room types have.
     *
     * @var array<string, list<string>>
     */
    private const array ROOM_AMENITIES = [
        '*' => ['Wi-Fi', 'Air conditioning', 'Desk', 'Wardrobe', 'Reading light', 'Socket near the bed', 'Key card access', 'Linen'],
        '4-Bed Male Room' => ['Lockers', 'Private curtain', 'Shared bathroom'],
        '4-Bed Female Room' => ['Lockers', 'Private curtain', 'Shared bathroom'],
        'Twin Sharing' => ['Lockers', 'Private bathroom', 'Shower', 'Towels'],
        'Single Study Room' => ['Shared bathroom', 'USB charging ports'],
        'Studio' => ['Private bathroom', 'Shower', 'Towels', 'Kitchenette', 'Refrigerator', 'Microwave', 'Electric kettle', 'City view'],
    ];

    private const string ABOUT = 'Varsity Loft is a student hostel a short walk from the main gate of Universiti Malaysia Sabah in Kota Kinabalu. Stay for a semester in a shared room on the male or female floor, a twin room or a single study room, or book a studio for a visit. Every bed has its own desk, wardrobe and reading light. Prices drop for stays of a month or more. There is a campus shuttle, a self-service laundry, a shared kitchen, a surau and quiet study rooms.';

    /**
     * Guests: name, nationality (ISO code), and the home town shown on the profile.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const array GUESTS = [
        ['Aidil Fahmi', 'MY', 'Penampang'], ['Nurul Syafiqah', 'MY', 'Ranau'], ['Jeremy Lingam', 'MY', 'Keningau'],
        ['Clarissa Gimbang', 'MY', 'Tambunan'], ['Mohd Haziq', 'MY', 'Tawau'], ['Siti Aishah', 'MY', 'Sandakan'],
        ['Vivian Majinbon', 'MY', 'Kota Belud'], ['Danial Iskandar', 'MY', 'Lahad Datu'], ['Melvin Gaban', 'MY', 'Kudat'],
        ['Amanda Jitinu', 'MY', 'Tuaran'], ['Wong Jia Hui', 'MY', 'Kuching'], ['Nicholas Ngau', 'MY', 'Miri'],
        ['Muhammad Irfan', 'MY', 'Shah Alam'], ['Tan Mei Ling', 'MY', 'Ipoh'], ['Lee Wei Jie', 'MY', 'Johor Bahru'],
        ['Rizky Pratama', 'ID', 'Makassar'], ['Dewi Anggraini', 'ID', 'Balikpapan'], ['Zhang Yiming', 'CN', 'Kunming'],
        ['Liu Xinyi', 'CN', 'Nanning'], ['Tanvir Hossain', 'BD', 'Dhaka'], ['Chinedu Okafor', 'NG', 'Enugu'],
        ['Ahmed Al-Hakimi', 'YE', 'Taiz'], ['Fatin Najwa', 'MY', 'Papar'], ['Grace Lajungan', 'MY', 'Beaufort'],
        ['Hairul Azman', 'MY', 'Semporna'], ['Joanna Sitin', 'MY', 'Kota Marudu'], ['Kelvin Chong', 'MY', 'Kota Kinabalu'],
        ['Rosnah Sulaiman', 'MY', 'Keningau'], ['Patrick Gimbang', 'MY', 'Tambunan'], ['Nadia Rahim', 'MY', 'Kuala Lumpur'],
        ['Farhan Zulkifli', 'MY', 'Kota Bharu'], ['Elena Kiew', 'MY', 'Sibu'], ['Azlan Mansur', 'MY', 'Kinabatangan'],
        ['Brandon Lojuntin', 'MY', 'Tenom'], ['Iqmal Hakim', 'MY', 'Putatan'],
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
        $this->seedDiscount($property, $owner);
        $roomTypes = $this->seedRooms($property, $owner);
        $plans = $this->seedRatePlans($property, $owner, $roomTypes, $today);
        $this->blockBed($property, $owner, $today);
        $guests = $this->seedGuests($property, $owner);
        $companies = $this->seedCompanies($property, $owner);
        $this->seedReservations($property, $owner, $roomTypes, $plans, $items, $guests, $companies, $today);
        $this->seedHousekeeping($property, $owner);
        $this->seedAmenities($property, $owner, $roomTypes);
        $this->seedBookingSite($property, $owner, $plans);

        $this->command?->info(self::NAME.' seeded: '.Reservation::query()->where('property_id', $property->id)->count().' reservations, '.$property->beds()->count().' beds.');
    }

    private function createProperty(): Property
    {
        $property = (new Property)->forceFill([
            'code' => app(IssuePropertyCode::class)(),
            'name' => self::NAME,
            'type' => PropertyType::Hostel,
            'status' => PropertyStatus::Onboarding,
            'address_line1' => '12 Jalan Sulaman Indah',
            'city' => 'Kota Kinabalu',
            'state_code' => 'Sabah',
            'postal_code' => '88400',
            'country_code' => 'MY',
            'contact_email' => 'hello@varsityloft.test',
            'contact_phone' => '+6088320123',
            'onboarding_started_at' => now()->subDays(14),
        ]);
        $property->save();

        app(GenerateDocumentPrefix::class)($property);
        app(SeedPropertyDefaults::class)($property, PropertyType::Hostel, 'MY');
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
            DefaultRoleEnum::Owner->value => 'Jonathan Liew',
            DefaultRoleEnum::Manager->value => 'Norhayati Sabli',
            DefaultRoleEnum::FrontDesk->value => 'Shafiq Front Desk',
            DefaultRoleEnum::Housekeeping->value => 'Lydia Housekeeping',
        ];
        $users = [];

        foreach ([DefaultRoleEnum::Owner, DefaultRoleEnum::Manager, DefaultRoleEnum::FrontDesk, DefaultRoleEnum::Housekeeping] as $role) {
            $user = new User(['name' => $names[$role->value], 'email' => str_replace('_', '', $role->value).'@varsityloft.com', 'password' => $password]);
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
            throw new RuntimeException('Set BORENO_SEED_PASSWORD to at least 8 characters before seeding Varsity Loft.');
        }

        return $password;
    }

    /**
     * Enable Tourism Tax on room revenue, as Kopi Lane does. SST stays off.
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
            ['Laundry token', 'LAUNDRY_TOKEN', TransactionCategory::Fee, '5.00', ChargingUnit::PerItem],
            ['Printing (per page)', 'PRINTING', TransactionCategory::Fee, '0.20', ChargingUnit::PerItem],
            ['Locker rental', 'LOCKER', TransactionCategory::Fee, '20.00', ChargingUnit::PerStay],
            ['Replacement key card', 'KEY_CARD', TransactionCategory::Fee, '20.00', ChargingUnit::PerItem],
            ['Late check-in (after 23:00)', 'LATE_CHECK_IN', TransactionCategory::Fee, '10.00', ChargingUnit::PerItem],
            ['Campus shuttle (monthly pass)', 'SHUTTLE_PASS', TransactionCategory::Fee, '60.00', ChargingUnit::PerItem],
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
            'name' => 'Alumni',
            'code' => 'ALUMNI',
            'type' => DiscountType::Percent->value,
            'value' => '10.00',
            'allow_override' => false,
            'scope' => DiscountScope::RoomOnly->value,
            'active' => true,
            'transaction_code_ids' => [],
        ]);
    }

    /**
     * Create the room types, their rooms, and the beds of each shared room. Beds are labelled by room letter.
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
                'max_children' => 0,
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
     * Rename the starter plan to Room Only for the whole rooms, add Shared Bed (priced per bed) for the shared rooms,
     * give both the month and semester discounts, and price the next 200 days.
     *
     * @param  array<string, RoomType>  $roomTypes
     * @return array<string, RatePlan> Keyed by name.
     */
    private function seedRatePlans(Property $property, User $owner, array $roomTypes, CarbonImmutable $today): array
    {
        $bedTypes = ['4-Bed Male Room', '4-Bed Female Room', 'Twin Sharing'];
        $roomOnlyTypes = ['Single Study Room', 'Studio'];

        $roomOnly = RatePlan::query()->where('property_id', $property->id)->orderBy('position')->firstOrFail();
        app(UpdateRatePlan::class)($owner, $roomOnly, ['name' => 'Room Only', 'description' => 'A whole room, no meals.', 'extra_adult_amount' => '30.00', 'extra_child_amount' => '15.00']);

        $sharedBed = app(CreateRatePlan::class)($property, $owner, [
            'name' => 'Shared Bed',
            'description' => 'One bed in a shared room, with a study desk and Wi-Fi.',
            'pricing_basis' => PricingBasis::PerBed->value,
        ], array_map(fn (string $name): int => $roomTypes[$name]->id, $bedTypes));

        $plans = ['Room Only' => $roomOnly->refresh(), 'Shared Bed' => $sharedBed];

        foreach ([['Room Only', $roomOnlyTypes], ['Shared Bed', $bedTypes]] as [$planName, $roomTypeNames]) {
            $rates = [];

            foreach (self::ROOM_TYPES as $definition) {
                if (! in_array($definition['name'], $roomTypeNames, true)) {
                    continue;
                }

                $days = [];

                foreach (range(1, 7) as $weekday) {
                    $days[$weekday] = ['amount' => (string) BigDecimal::of($definition['price'])->toScale(2), 'occupancy' => []];
                }

                $rates[] = ['room_type_id' => $roomTypes[$definition['name']]->id, 'days' => $days];
            }

            if (app(SaveRates::class)($owner, $plans[$planName], $today->toDateString(), $today->addDays(200)->toDateString(), $rates) === false) {
                throw new RuntimeException("Could not save rates for {$planName}.");
            }

            if (! app(SaveRatePlanStayDiscounts::class)($owner, $plans[$planName], self::STAY_DISCOUNTS)) {
                throw new RuntimeException("Could not save the long-stay discounts of {$planName}.");
            }
        }

        return $plans;
    }

    /**
     * Block bed A4 in room 101 for five nights from today: its desk lamp and socket are being rewired. The room stays
     * on sale.
     */
    private function blockBed(Property $property, User $owner, CarbonImmutable $today): void
    {
        $room = Room::query()->where('property_id', $property->id)->where('number', '101')->firstOrFail();
        $bed = Bed::query()->where('room_id', $room->id)->where('label', 'A4')->firstOrFail();

        $result = app(CreateRoomBlock::class)($owner, $room, $bed, new RoomBlockInputData(
            reason: RoomBlockReason::Maintenance,
            startsOn: $today->toDateString(),
            endsOn: $today->addDays(4)->toDateString(),
            notes: 'Desk socket is being rewired. Electrician comes on Monday.',
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
                'email' => trim($slug, '.').'@students.test',
                'phone' => '+6013'.str_pad((string) (7200000 + $index * 191), 7, '0', STR_PAD_LEFT),
                'nationality_code' => $nationality,
                'country_code' => $nationality,
                'city' => $city,
            ]);
        }

        return $guests;
    }

    /**
     * The university, billed for its intake groups, and a foundation that sponsors students.
     *
     * @return array{university: Company, sponsor: Company}
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
            'university' => $create('Universiti Malaysia Sabah', CompanyType::Government, null, 60, 'kediaman@ums.test'),
            'sponsor' => $create('Yayasan Sabah', CompanyType::Corporate, '50000.00', 30, 'biasiswa@yayasansabah.test'),
        ];
    }

    /**
     * About 16 reservations from the business date on: semester stays in house, move-ins today, a university intake
     * group on all of rooms 103 and 104 (one bill to the university), a sponsored student, short stays for parents,
     * an interview and exam week, a no-show, a cancellation, and a sponsored stay settled to the city ledger.
     *
     * @param  array<string, RoomType>  $roomTypes
     * @param  array<string, RatePlan>  $plans
     * @param  array<string, ChargeItem>  $items
     * @param  list<Guest>  $guests
     * @param  array{university: Company, sponsor: Company}  $companies
     */
    private function seedReservations(Property $property, User $owner, array $roomTypes, array $plans, array $items, array $guests, array $companies, CarbonImmutable $today): void
    {
        /**
         * Book one line. A bed type takes one bed per guest in `$guestIndexes`, one adult each; a whole room takes
         * `adults` (default 1). The first guest is the booker. With `rooms`, the beds come from those rooms in order;
         * otherwise `auto_assign_rooms` assigns them.
         *
         * @param  list<int>  $guestIndexes
         * @param  array{source?: string, billing?: BillingMode, group?: string, notes?: string, rooms?: list<string>, adults?: int, company?: int}  $options
         */
        $book = function (string $roomTypeName, int $startOffset, int $nights, array $guestIndexes, array $options = []) use ($property, $owner, $roomTypes, $plans, $guests, $today): Reservation {
            $roomType = $roomTypes[$roomTypeName];
            $isBed = $roomType->sell_unit === SellUnit::Bed;

            $result = app(CreateBooking::class)($owner, $property, new NewBookingData(
                bookingSource: $this->bookingSource($property, $options['source'] ?? null),
                units: [new NewUnitData(
                    roomTypeId: $roomType->id,
                    ratePlanId: $plans[$isBed ? 'Shared Bed' : 'Room Only']->id,
                    arrivalDate: $today->addDays($startOffset),
                    departureDate: $today->addDays($startOffset + $nights),
                    adults: $isBed ? 1 : ($options['adults'] ?? 1),
                    beds: $isBed ? count($guestIndexes) : 1,
                )],
                guests: array_map(fn (int $guestIndex, int $unitIndex): NewBookingGuestData => new NewBookingGuestData(guestId: $guests[$guestIndex]->id, unitIndex: $isBed ? $unitIndex : 0), $guestIndexes, array_keys($guestIndexes)),
                picks: isset($options['rooms']) ? $this->bedPicks($property, $options['rooms'], count($guestIndexes)) : [],
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
        $book('4-Bed Male Room', 5, 7, [2, 12, 14, 24, 26, 32, 33, 34], ['rooms' => ['103', '104'], 'company' => $companies['university']->id, 'group' => 'UMS Faculty of Engineering intake', 'notes' => 'Orientation week. Eight new students on all of rooms 103 and 104. One bill to the university.']);
        $book('4-Bed Male Room', 2, 90, [8], ['company' => $companies['sponsor']->id, 'notes' => 'Yayasan Sabah scholar. Rent billed to the foundation; extras paid by the student.']);
        $book('Twin Sharing', 7, 30, [13, 10], ['billing' => BillingMode::EachPays, 'notes' => 'Two friends from Ipoh and Kuching. Each pays for their own bed.']);
        $book('Single Study Room', 14, 120, [21], ['source' => 'Email', 'notes' => 'International postgraduate. Needs a letter of stay for the visa office.']);
        $book('Single Study Room', 3, 1, [29], ['source' => 'Phone', 'notes' => 'Interview at the Faculty of Medicine the next morning.']);
        $book('Studio', 10, 2, [27, 28], ['adults' => 2, 'notes' => 'Parents of Clarissa Gimbang, here for her graduation.']);
        $book('4-Bed Female Room', 30, 14, [31], ['notes' => 'Exam weeks only. Goes home for the holidays after.']);

        // Move-ins today, left Confirmed to try check-in.
        $book('4-Bed Male Room', 0, 120, [4, 7], ['rooms' => ['102'], 'billing' => BillingMode::EachPays, 'notes' => 'Coursemates moving in together. Each pays their own semester.']);
        $book('4-Bed Female Room', 0, 120, [5], ['notes' => 'Arriving with her parents on the afternoon flight from Sandakan.']);
        $book('Studio', 0, 90, [17], ['notes' => 'PhD candidate, Faculty of Science and Natural Resources.']);

        // In house.
        $aidil = $book('4-Bed Male Room', 0, 120, [0], ['source' => 'Walk-in']);
        $nurul = $book('4-Bed Female Room', 0, 120, [1]);
        $rizky = $book('Single Study Room', 0, 90, [15], ['notes' => 'Exchange student from Universitas Hasanuddin for one semester.']);

        $noShow = $book('Single Study Room', 0, 1, [20]);
        $this->forEachUnit($noShow, fn (ReservationUnit $unit): UnitStayResult => app(MarkNoShow::class)($owner, $unit), 'The no-show');

        $cancelled = $book('4-Bed Male Room', 9, 30, [19], ['notes' => 'Moved to campus housing.']);
        $this->forEachUnit($cancelled, fn (ReservationUnit $unit): UnitStayResult => app(CancelUnit::class)($owner, $unit), 'The cancellation');

        $methods = PaymentMethod::query()->where('property_id', $property->id)->where('active', true)->get()->keyBy(fn (PaymentMethod $method): string => $method->type->value);

        $aidilUnit = $this->checkIn($owner, $aidil)->first();
        $this->charge($owner, $aidilUnit, $items['LOCKER']);
        $this->charge($owner, $aidilUnit, $items['LAUNDRY_TOKEN'], 4);
        $this->pay($owner, $aidil, $this->unitFolio($aidilUnit), $methods[PaymentMethodType::Cash->value], PaymentPurpose::SecurityDeposit, '200.00');
        $this->pay($owner, $aidil, $this->unitFolio($aidilUnit), $methods[PaymentMethodType::BankTransfer->value], PaymentPurpose::Advance, '700.00');

        $nurulUnit = $this->checkIn($owner, $nurul)->first();
        $this->charge($owner, $nurulUnit, $items['PRINTING'], 40);
        $this->charge($owner, $nurulUnit, $items['SHUTTLE_PASS']);
        $this->pay($owner, $nurul, $this->unitFolio($nurulUnit), $methods[PaymentMethodType::EWallet->value], PaymentPurpose::SecurityDeposit, '200.00');

        $rizkyUnit = $this->checkIn($owner, $rizky)->first();
        $this->charge($owner, $rizkyUnit, $items['KEY_CARD']);
        $this->charge($owner, $rizkyUnit, $items['LATE_CHECK_IN']);
        $this->pay($owner, $rizky, $this->unitFolio($rizkyUnit), $methods[PaymentMethodType::BankTransfer->value], PaymentPurpose::Advance, '1500.00');

        $this->seedCityLedger($owner, $book, $items, $companies['sponsor'], $methods[PaymentMethodType::BankTransfer->value]);
    }

    /**
     * A one-night stay for a Yayasan Sabah scholar from today: checked in, the locker and shuttle pass moved to the
     * foundation's folio, checked out and settled to the city ledger. The foundation has paid part of the invoice.
     *
     * @param  callable(string, int, int, list<int>, array<string, mixed>): Reservation  $book
     * @param  array<string, ChargeItem>  $items
     */
    private function seedCityLedger(User $owner, callable $book, array $items, Company $sponsor, PaymentMethod $bankTransfer): void
    {
        $reservation = $book('Twin Sharing', 0, 1, [30], ['company' => $sponsor->id, 'notes' => 'Yayasan Sabah scholar. Moves to a twin room on campus tomorrow.']);
        $unit = $this->checkIn($owner, $reservation)->first();
        $lines = [$this->charge($owner, $unit, $items['LOCKER']), $this->charge($owner, $unit, $items['SHUTTLE_PASS'])];
        $sponsorFolio = app(CreateFolio::class)->thirdParty($reservation, null, $sponsor->id, $owner);
        $transferred = app(TransferLines::class)($owner, $lines, $sponsorFolio);

        if ($transferred->isRefused()) {
            throw new RuntimeException("The transfer to {$sponsor->name} was refused: {$transferred->refusal}.");
        }

        $this->checkOut($unit);

        $unitFolio = $this->unitFolio($unit)->refresh();

        if ($unitFolio->status === FolioStatus::Open && BigDecimal::of(app(FolioBalance::class)->forFolio($unitFolio))->isZero()) {
            $closed = app(CloseFolio::class)($owner, $unitFolio);

            if ($closed->isRefused()) {
                throw new RuntimeException("Closing the scholar's folio was refused: {$closed->refusal}.");
            }
        }

        $settled = app(SettleToCityLedger::class)($owner, $sponsorFolio->refresh());

        if ($settled->isRefused() || ! $settled->invoice instanceof ArInvoice) {
            throw new RuntimeException("Settling {$sponsor->name}'s folio was refused: {$settled->refusal}.");
        }

        $payment = app(RecordArPayment::class)($owner, $sponsor, new NewArPaymentData(
            paymentMethodId: $bankTransfer->id,
            amount: '50.00',
            idempotencyKey: (string) Str::uuid(),
            reference: 'TT-'.Str::upper(Str::random(6)),
        ), allocate: true);

        if ($payment->isRefused()) {
            throw new RuntimeException("The payment from {$sponsor->name} was refused: {$payment->refusal}.");
        }
    }

    /**
     * Room statuses for the board: dirty rooms after move-out, an inspected room ready for move-in, and a remark.
     */
    private function seedHousekeeping(Property $property, User $owner): void
    {
        $rooms = Room::query()->where('property_id', $property->id)->get()->keyBy('number');

        foreach (['102' => HousekeepingStatus::Dirty, '306' => HousekeepingStatus::Dirty, '401' => HousekeepingStatus::Inspected] as $number => $status) {
            $refusal = app(UpdateRoomStatus::class)($owner, $rooms[$number], $status);

            if ($refusal !== null) {
                throw new RuntimeException("Room {$number} could not be set to {$status->value}.");
            }
        }

        app(SetRoomRemark::class)($owner, $rooms['102'], 'Deep clean after the semester. Check every desk lamp and wardrobe lock.');
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
     * Turn on the booking engine in Microsite mode, make both plans bookable online, and save the About text and the
     * location near the university. The site is not published: it needs a template, images and photos first.
     *
     * @param  array<string, RatePlan>  $plans
     */
    private function seedBookingSite(Property $property, User $owner, array $plans): void
    {
        Feature::for($property)->activate(BookingEngine::class);
        app(SetUpBookingSite::class)($property);
        $property->refresh();

        foreach ($plans as $planName => $plan) {
            if (app(SetRatePlanBookableOnline::class)($owner, $property, $plan->id, true) !== BookableOnlineResult::Saved) {
                throw new RuntimeException("Could not make {$planName} bookable online.");
            }
        }

        $mapsUrl = 'https://www.google.com/maps/search/?api=1&query=6.0367,116.1186';

        app(SaveBookingWebsiteGeneral::class)($property, $owner, (string) $property->slug, $mapsUrl, self::ABOUT, new MapsPlace(self::NAME, '6.0367', '116.1186', $mapsUrl));
    }

    /**
     * Pick the first `$count` active beds across the given rooms, in room order, one per unit.
     *
     * @param  list<string>  $roomNumbers
     * @return list<UnitPickData>
     */
    private function bedPicks(Property $property, array $roomNumbers, int $count): array
    {
        $bedIds = [];

        foreach ($roomNumbers as $roomNumber) {
            $room = Room::query()->where('property_id', $property->id)->where('number', $roomNumber)->firstOrFail();
            array_push($bedIds, ...array_map(intval(...), Bed::query()->where('room_id', $room->id)->active()->ordered()->pluck('id')->all()));
        }

        $bedIds = array_slice($bedIds, 0, $count);

        return array_map(fn (int $bedId, int $unitIndex): UnitPickData => new UnitPickData(unitIndex: $unitIndex, bedId: $bedId), $bedIds, array_keys($bedIds));
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
