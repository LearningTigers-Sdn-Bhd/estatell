<?php

namespace Database\Seeders;

use App\Actions\Catalogues\CreateChargeItem;
use App\Actions\Catalogues\UpdateRoomRevenueTaxes;
use App\Actions\Catalogues\UpdateTax;
use App\Actions\NightAudit\OpenBusinessDate;
use App\Actions\Platform\AddPropertyToGroup;
use App\Actions\Property\GenerateDocumentPrefix;
use App\Actions\Property\IssuePropertyCode;
use App\Actions\Property\SeedPropertyDefaults;
use App\Actions\Rates\SaveRates;
use App\Actions\Rates\UpdateRatePlan;
use App\Actions\Rooms\CreateRoomType;
use App\Actions\Rooms\GenerateRooms;
use App\Actions\Staff\AssignOwnerRole;
use App\Actions\Staff\AssignStaffRole;
use App\Actions\Staff\GrantPropertyAccess;
use App\Data\Catalogues\TaxInputData;
use App\Enums\ChargingUnit;
use App\Enums\DefaultRoleEnum;
use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Enums\SellUnit;
use App\Enums\TransactionCategory;
use App\Models\Property;
use App\Models\PropertyGroup;
use App\Models\RatePlan;
use App\Models\Role;
use App\Models\Tax;
use App\Models\User;
use App\Services\Property\PropertyToday;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Three more fictional lodges for the Demo group, so the group dashboard, forecast and reports compare five properties
 * of different kinds: Seri Pagi Hotel (a 24-room hotel in Sandakan), Kinabalu Mist Homestay (6 rooms in Kundasang) and
 * Likas Bay Suites (16 serviced apartments in Kota Kinabalu). Each is created Live through the production Actions with
 * its staff, taxes, extras, rooms and 200 days of rates, put in the Demo group, and opened to the Kopi Lane owner and
 * manager as Manager (as Boreno is). Then the demo seeders give them a year of business: `DemoYearSeeder`,
 * `DemoOperationsSeeder`, `DemoDailyOperationsSeeder` and `DemoCatchUpSeeder`, for these three lodges only, so Boreno
 * and Kopi Lane are not touched.
 *
 * Run it on a local or staging database only: `php artisan db:seed --class=DemoGroupLodgesSeeder`. A lodge that already
 * exists is skipped. Staff sign in as `{slug}@demo.estatell.com` (and `.manager`, `.frontdesk`, `.housekeeping`) with
 * `DEMO_PASSWORD`. See docs/01-platform/06-demo-properties.md.
 */
class DemoGroupLodgesSeeder extends Seeder
{
    private const string GROUP = 'Demo group';

    /** Staff of the group who get Manager access at each new lodge, by login. */
    private const array GROUP_STAFF = ['kopilane@demo.estatell.com', 'kopilane.manager@demo.estatell.com'];

    /**
     * The lodges: login slug, type, address, staff names (owner, manager, front desk, housekeeping), whether SST is
     * charged, extra charge items (name, code, category, amount, unit) and room types (name, description, room numbers,
     * group, base occupancy, adults, children, weekday and Friday/Saturday price).
     *
     * @var array<string, array{slug: string, type: PropertyType, address: string, city: string, postal: string, phone: string, staff: list<string>, sst: bool, items: list<array{0: string, 1: string, 2: TransactionCategory, 3: string, 4: ChargingUnit}>, rooms: list<array{name: string, description: string, numbers: list<string>, group: string, base: int, adults: int, children: int, weekday: string, weekend: string}>}>
     */
    public const array LODGES = [
        'Seri Pagi Hotel' => [
            'slug' => 'seripagi',
            'type' => PropertyType::Hotel,
            'address' => '18 Jalan Pryer',
            'city' => 'Sandakan',
            'postal' => '90000',
            'phone' => '+6089221188',
            'staff' => ['Lim Chee Keong', 'Rosnah Abdullah', 'Kelvin Front Desk', 'Maria Housekeeping'],
            'sst' => true,
            'items' => [
                ['Breakfast', 'BREAKFAST', TransactionCategory::FoodAndBeverage, '28.00', ChargingUnit::PerGuestNight],
                ['Minibar drink', 'MINIBAR_DRINK', TransactionCategory::FoodAndBeverage, '10.00', ChargingUnit::PerItem],
                ['Laundry (per piece)', 'LAUNDRY_PIECE', TransactionCategory::Fee, '18.00', ChargingUnit::PerItem],
                ['Parking', 'PARKING', TransactionCategory::Fee, '10.00', ChargingUnit::PerNight],
            ],
            'rooms' => [
                ['name' => 'Superior Queen', 'description' => 'Queen bed, city view and a work desk.', 'numbers' => ['201', '202', '203', '204', '205', '206', '207', '208', '209', '210'], 'group' => 'Level 2', 'base' => 2, 'adults' => 2, 'children' => 1, 'weekday' => '158.00', 'weekend' => '178.00'],
                ['name' => 'Deluxe Twin', 'description' => 'Two single beds and a sitting corner.', 'numbers' => ['301', '302', '303', '304', '305', '306', '307', '308'], 'group' => 'Level 3', 'base' => 2, 'adults' => 2, 'children' => 1, 'weekday' => '188.00', 'weekend' => '208.00'],
                ['name' => 'Harbour Suite', 'description' => 'King bed, a lounge and a balcony over Sandakan Bay.', 'numbers' => ['401', '402', '403', '404', '405', '406'], 'group' => 'Level 4', 'base' => 2, 'adults' => 3, 'children' => 2, 'weekday' => '298.00', 'weekend' => '338.00'],
            ],
        ],
        'Kinabalu Mist Homestay' => [
            'slug' => 'kinabalumist',
            'type' => PropertyType::Homestay,
            'address' => 'Lot 12, Jalan Mesilau',
            'city' => 'Kundasang',
            'postal' => '89308',
            'phone' => '+6088889012',
            'staff' => ['Juliana Gimbang', 'Ronald Sipaun', 'Agnes Front Desk', 'Linda Housekeeping'],
            'sst' => false,
            'items' => [
                ['Kampung breakfast', 'BREAKFAST', TransactionCategory::FoodAndBeverage, '18.00', ChargingUnit::PerGuestNight],
                ['Extra blanket', 'TOWEL', TransactionCategory::Fee, '5.00', ChargingUnit::PerStay],
                ['Laundry (per load)', 'LAUNDRY_LOAD', TransactionCategory::Fee, '12.00', ChargingUnit::PerItem],
            ],
            'rooms' => [
                ['name' => 'Garden Room', 'description' => 'Queen bed, a heater and a view of the vegetable farms.', 'numbers' => ['G1', 'G2', 'G3'], 'group' => 'Garden', 'base' => 2, 'adults' => 2, 'children' => 1, 'weekday' => '120.00', 'weekend' => '150.00'],
                ['name' => 'Mountain View Family Room', 'description' => 'A queen and two single beds, facing Mount Kinabalu.', 'numbers' => ['M1', 'M2', 'M3'], 'group' => 'Upstairs', 'base' => 2, 'adults' => 4, 'children' => 2, 'weekday' => '190.00', 'weekend' => '240.00'],
            ],
        ],
        'Likas Bay Suites' => [
            'slug' => 'likasbay',
            'type' => PropertyType::ServicedApartment,
            'address' => '3 Jalan Likas',
            'city' => 'Kota Kinabalu',
            'postal' => '88400',
            'phone' => '+6088265533',
            'staff' => ['Vincent Chin', 'Nadia Yusof', 'Hazwan Front Desk', 'Rita Housekeeping'],
            'sst' => true,
            'items' => [
                ['Parking', 'PARKING', TransactionCategory::Fee, '8.00', ChargingUnit::PerNight],
                ['Laundry (per load)', 'LAUNDRY_LOAD', TransactionCategory::Fee, '20.00', ChargingUnit::PerItem],
                ['Mid-stay cleaning', 'CLEANING', TransactionCategory::Fee, '45.00', ChargingUnit::PerItem],
            ],
            'rooms' => [
                ['name' => 'Studio', 'description' => 'Queen bed, kitchenette and a washer.', 'numbers' => ['A-0501', 'A-0502', 'A-0503', 'A-0504', 'A-0505', 'A-0506'], 'group' => 'Tower A', 'base' => 2, 'adults' => 2, 'children' => 1, 'weekday' => '168.00', 'weekend' => '188.00'],
                ['name' => 'One-Bedroom Suite', 'description' => 'A bedroom, a living room and a full kitchen.', 'numbers' => ['A-1001', 'A-1002', 'A-1003', 'A-1004', 'A-1005', 'A-1006'], 'group' => 'Tower A', 'base' => 2, 'adults' => 3, 'children' => 1, 'weekday' => '228.00', 'weekend' => '258.00'],
                ['name' => 'Two-Bedroom Sea View', 'description' => 'Two bedrooms, two bathrooms and a balcony over Likas Bay.', 'numbers' => ['B-1501', 'B-1502', 'B-1503', 'B-1504'], 'group' => 'Tower B', 'base' => 4, 'adults' => 5, 'children' => 2, 'weekday' => '358.00', 'weekend' => '398.00'],
            ],
        ],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoGroupLodgesSeeder writes demo data and never runs in production.');
        }

        $group = PropertyGroup::query()->where('name', self::GROUP)->first()
            ?? throw new RuntimeException('Create the "'.self::GROUP.'" property group first.');

        foreach (self::LODGES as $name => $lodge) {
            if (Property::query()->where('name', $name)->exists()) {
                $this->command?->warn("{$name} already exists; not created again.");

                continue;
            }

            DB::transaction(fn () => $this->seedLodge($group, $name, $lodge));
            $this->command?->info("{$name} created in {$group->name}.");
        }

        $names = array_keys(self::LODGES);

        foreach ([DemoYearSeeder::class, DemoOperationsSeeder::class, DemoDailyOperationsSeeder::class, DemoCatchUpSeeder::class] as $class) {
            $seeder = app($class)->only($names);
            $seeder->setContainer($this->container);

            if ($this->command !== null) {
                $seeder->setCommand($this->command);
            }

            $seeder->__invoke();
        }
    }

    /**
     * @param  array{slug: string, type: PropertyType, address: string, city: string, postal: string, phone: string, staff: list<string>, sst: bool, items: list<array{0: string, 1: string, 2: TransactionCategory, 3: string, 4: ChargingUnit}>, rooms: list<array{name: string, description: string, numbers: list<string>, group: string, base: int, adults: int, children: int, weekday: string, weekend: string}>}  $lodge
     */
    private function seedLodge(PropertyGroup $group, string $name, array $lodge): void
    {
        $property = (new Property)->forceFill([
            'code' => app(IssuePropertyCode::class)(),
            'name' => $name,
            'type' => $lodge['type'],
            'status' => PropertyStatus::Onboarding,
            'address_line1' => $lodge['address'],
            'city' => $lodge['city'],
            'state_code' => 'Sabah',
            'postal_code' => $lodge['postal'],
            'country_code' => 'MY',
            'contact_email' => "hello@{$lodge['slug']}.test",
            'contact_phone' => $lodge['phone'],
            'onboarding_started_at' => now()->subDays(14),
        ]);
        $property->save();

        app(GenerateDocumentPrefix::class)($property);
        app(SeedPropertyDefaults::class)($property, $lodge['type'], 'MY');
        $property->forceFill(['status' => PropertyStatus::Live, 'went_live_at' => now()])->save();
        $property->refresh();

        $owner = $this->createStaff($property, $lodge['slug'], $lodge['staff']);
        $today = CarbonImmutable::parse(app(PropertyToday::class)->date($property)->toDateString());

        app(OpenBusinessDate::class)($property, $today, $owner);

        $this->seedTaxes($property, $owner, $lodge['sst']);
        $this->seedChargeItems($property, $owner, $lodge['items']);
        $this->seedRoomsAndRates($property, $owner, $lodge['rooms'], $today);

        if (! app(AddPropertyToGroup::class)($owner, $group, $property)) {
            throw new RuntimeException("{$name} could not join {$group->name}.");
        }

        $this->openToGroupStaff($property, $owner);
    }

    /**
     * Create the four staff accounts and return the Owner.
     *
     * @param  list<string>  $names  Owner, manager, front desk and housekeeping.
     */
    private function createStaff(Property $property, string $slug, array $names): User
    {
        $roles = [DefaultRoleEnum::Owner, DefaultRoleEnum::Manager, DefaultRoleEnum::FrontDesk, DefaultRoleEnum::Housekeeping];
        $users = [];

        foreach ($roles as $index => $role) {
            $user = new User(['name' => $names[$index], 'email' => DemoSeeder::loginEmail($slug, $role), 'password' => DemoSeeder::password()]);
            $user->property_id = $property->id;
            $user->email_verified_at = now();
            $user->email_device_check = false;
            $user->save();
            $user->memberships()->create(['property_id' => $property->id]);
            $users[$role->value] = $user;
        }

        $owner = $users[DefaultRoleEnum::Owner->value];
        app(AssignOwnerRole::class)($property, $owner);

        foreach (array_slice($roles, 1) as $role) {
            $roleModel = Role::query()->where('property_id', $property->id)->where('default_role', $role)->firstOrFail();

            if (! app(AssignStaffRole::class)($owner, $property, $users[$role->value], $roleModel)) {
                throw new RuntimeException("Could not assign the {$role->value} role at {$property->name}.");
            }
        }

        return $owner;
    }

    /**
     * Tourism Tax on room revenue everywhere, and SST too where the lodge is above the threshold.
     */
    private function seedTaxes(Property $property, User $owner, bool $sst): void
    {
        $codes = $sst ? ['SST', 'TTX'] : ['TTX'];
        $taxes = Tax::query()->where('property_id', $property->id)->whereIn('code', $codes)->get();

        foreach ($taxes as $tax) {
            app(UpdateTax::class)($owner, $tax, TaxInputData::fromModel($tax, enabled: true));
        }

        app(UpdateRoomRevenueTaxes::class)($property, $owner, $taxes->modelKeys());
    }

    /**
     * @param  list<array{0: string, 1: string, 2: TransactionCategory, 3: string, 4: ChargingUnit}>  $items
     */
    private function seedChargeItems(Property $property, User $owner, array $items): void
    {
        foreach ($items as [$name, $code, $category, $amount, $unit]) {
            app(CreateChargeItem::class)($property, $owner, [
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
     * Create the room types and rooms, rename the starter plan (which takes every room type) to Room Only, and price the next 200
     * days. Friday and Saturday nights cost more.
     *
     * @param  list<array{name: string, description: string, numbers: list<string>, group: string, base: int, adults: int, children: int, weekday: string, weekend: string}>  $definitions
     */
    private function seedRoomsAndRates(Property $property, User $owner, array $definitions, CarbonImmutable $today): void
    {
        $rates = [];

        foreach ($definitions as $definition) {
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

            $plan = array_map(fn (string $number): array => ['number' => $number, 'group' => $definition['group']], $definition['numbers']);
            $result = app(GenerateRooms::class)($property, $owner, $roomType, null, $plan);

            if ($result['conflicts'] !== []) {
                throw new RuntimeException('Room numbers already taken: '.implode(', ', $result['conflicts']));
            }

            $days = [];

            foreach (range(1, 7) as $weekday) {
                $amount = in_array($weekday, [5, 6], true) ? $definition['weekend'] : $definition['weekday'];
                $days[$weekday] = ['amount' => (string) BigDecimal::of($amount)->toScale(2), 'occupancy' => []];
            }

            $rates[] = ['room_type_id' => $roomType->id, 'days' => $days];
        }

        $roomOnly = RatePlan::query()->where('property_id', $property->id)->orderBy('position')->firstOrFail();
        app(UpdateRatePlan::class)($owner, $roomOnly, ['name' => 'Room Only', 'description' => 'Room only, no meals.', 'extra_adult_amount' => '40.00', 'extra_child_amount' => '20.00']);

        if (app(SaveRates::class)($owner, $roomOnly->refresh(), $today->toDateString(), $today->addDays(200)->toDateString(), $rates) === false) {
            throw new RuntimeException("Could not save rates at {$property->name}.");
        }
    }

    /**
     * Give the Kopi Lane owner and manager the Manager role here, so the group pages show the lodge to them.
     */
    private function openToGroupStaff(Property $property, User $owner): void
    {
        $manager = Role::query()->where('property_id', $property->id)->where('default_role', DefaultRoleEnum::Manager)->firstOrFail();

        foreach (User::query()->whereIn('email', self::GROUP_STAFF)->get() as $staff) {
            if (! app(GrantPropertyAccess::class)($owner, $property, $staff, $manager)) {
                throw new RuntimeException("{$staff->email} could not be given access to {$property->name}.");
            }
        }
    }
}
