<?php

use App\Actions\Property\SeedPropertyDefaults;
use App\Enums\ChargingUnit;
use App\Enums\CompanyAccess;
use App\Enums\CompanyRateMarket;
use App\Enums\OnboardingStep;
use App\Enums\PricingBasis;
use App\Enums\PropertyType;
use App\Models\ChargeItem;
use App\Models\DocumentSequence;
use App\Models\ImportLink;
use App\Models\OnboardingSkip;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->property = Property::factory()->onboarding()->create(['document_prefix' => 'GEH']);
    app(SeedPropertyDefaults::class)($this->property, PropertyType::Hotel, 'MY');
});

/**
 * @return array<string, list<array<string, string>>>
 */
function wastaysSetupTables(): array
{
    $codes = [
        [31, 'ROOM', 'ROOM'], [32, 'NO_SHOW', 'NO SHOW'], [33, 'CANCEL', 'CANCEL'], [34, 'LATE_CO', 'LATE CO'],
        [35, 'EARLY_DEP', 'EARLY DEP'], [36, 'SECDEP', 'SECDEP'], [37, 'TAX_SST', 'SST'], [38, 'TAX_TTX', 'TTX'],
        [39, 'FNB', 'FOOD & BEVERAGE'], [40, 'PARK', 'PARKING'], [41, 'DAMAGE', 'DAMAGE CHARGE'], [42, 'CLEANING', 'CLEANING FEE'],
        [43, 'OTA_FEE', 'OTA FEE'], [44, 'OTA_TAX', 'OTA TAX'], [45, 'MISC', 'MISC'], [46, 'CASH', 'CASH'],
        [47, 'CARD', 'CARD'], [48, 'BANK', 'BANK'], [49, 'GATEWAY', 'GATEWAY'], [50, 'OTA', 'OTA'],
        [51, 'REFUND', 'REFUND'], [52, 'ADJUSTMENT', 'ADJUSTMENT'], [53, 'REBATE', 'REBATE'], [54, 'DBKK', 'DBKK'],
        [63, 'BED', 'EXTRA BED'], [64, 'TAX', 'TTX'], [65, 'CASH_PRE', 'CASH PRE'], [66, 'CASH_ADV', 'CASH ADV'], [67, 'CARD_ADV', 'CARD ADV'],
    ];

    return [
        'hotels' => [['id' => '3', 'created_at' => '2026-08-13T08:02:00.038624Z', 'sst_enabled' => 't', 'sst_registration_number' => 'W10-1234-56789012']],
        'room_types' => [
            ['id' => '1', 'name' => 'SUPERIOR DELUXE TWIN', 'base_price' => '162.0', 'max_adults' => '2', 'max_children' => '0'],
            ['id' => '2', 'name' => 'SUPERIOR DELUXE FAMILY', 'base_price' => '192.24', 'max_adults' => '4', 'max_children' => '2'],
            ['id' => '3', 'name' => 'SUPER DELUXE QUEEN', 'base_price' => '143.64', 'max_adults' => '2', 'max_children' => '0'],
            ['id' => '4', 'name' => 'DELUXE QUEEN', 'base_price' => '138.24', 'max_adults' => '1', 'max_children' => '0'],
        ],
        'rooms' => [
            ['id' => '1', 'number' => '101', 'room_type_id' => '1', 'archived_at' => ''],
            ['id' => '2', 'number' => '102', 'room_type_id' => '1', 'archived_at' => ''],
            ['id' => '3', 'number' => '201', 'room_type_id' => '2', 'archived_at' => ''],
            ['id' => '4', 'number' => '301', 'room_type_id' => '3', 'archived_at' => '2026-09-01 00:00:00'],
        ],
        'rate_plans' => [
            ['id' => '1', 'name' => 'Standard', 'archived_at' => ''],
            ['id' => '2', 'name' => 'Walk-in', 'archived_at' => ''],
            ['id' => '3', 'name' => 'Corporate', 'archived_at' => ''],
            ['id' => '4', 'name' => 'Standard', 'archived_at' => ''],
        ],
        'hotel_taxes' => [['id' => '1', 'name' => 'DBKK', 'transaction_code_id' => '54']],
        'transaction_codes' => array_map(fn (array $code): array => [
            'id' => (string) $code[0], 'code' => $code[1], 'name' => $code[2], 'active' => $code[1] === 'PARK' ? 'f' : 't',
        ], $codes),
        'hotel_extra_charges' => [
            ['id' => '1', 'transaction_code_id' => '42', 'position' => '1'],
            ['id' => '2', 'transaction_code_id' => '41', 'position' => '2'],
            ['id' => '3', 'transaction_code_id' => '39', 'position' => '3'],
            ['id' => '4', 'transaction_code_id' => '45', 'position' => '4'],
            ['id' => '5', 'transaction_code_id' => '40', 'position' => '5'],
            ['id' => '6', 'transaction_code_id' => '63', 'position' => '6'],
            ['id' => '7', 'transaction_code_id' => '64', 'position' => '7'],
        ],
        'hotel_payment_methods' => array_map(fn (array $row): array => ['id' => (string) $row[0], 'transaction_code_id' => (string) $row[1]], [
            [1, 46], [2, 65], [3, 66], [4, 47], [5, 67], [6, 49], [7, 48], [8, 50],
        ]),
        'folio_transactions' => [
            ['id' => '1', 'transaction_code_id' => '31'],
            ['id' => '2', 'transaction_code_id' => '37'],
            ['id' => '3', 'transaction_code_id' => '46'],
        ],
    ];
}

function runSetupImport(?array $tables = null, array $options = []): PendingCommand
{
    return test()->artisan('wastays:import-setup', [
        'bundle' => wastaysBundleDir($tables ?? wastaysSetupTables()),
        'property' => test()->property->code,
        '--actor' => test()->admin->email,
        ...$options,
    ]);
}

function setupSnapshot(Property $property): array
{
    return [
        RoomType::query()->count(), Room::query()->count(), RatePlan::query()->count(), Rate::query()->count(),
        ChargeItem::query()->count(), ImportLink::query()->count(),
    ];
}

it('imports the room types, rooms, rate plans and prices', function () {
    runSetupImport()->assertSuccessful();

    $property = $this->property;
    $types = $property->roomTypes()->orderBy('id')->get();

    expect($types->pluck('name')->all())->toBe(['Superior Deluxe Twin', 'Superior Deluxe Family', 'Super Deluxe Queen', 'Deluxe Queen'])
        ->and($types->pluck('base_occupancy')->all())->toBe([2, 2, 2, 1])
        ->and($types[1]->max_children)->toBe(2)
        ->and($property->rooms()->pluck('number')->sort()->values()->all())->toBe(['101', '102', '201']);

    $plans = $property->ratePlans()->orderBy('position')->get()->keyBy('name');
    expect($plans->keys()->all())->toEqualCanonicalizing(['Basic Rate', 'Walk-in Rate', 'Corporate Rate'])
        ->and($plans['Walk-in Rate']->parent_rate_plan_id)->toBe($plans['Basic Rate']->id)
        ->and($plans['Corporate Rate']->company_access)->toBe(CompanyAccess::Everyone)
        ->and($plans['Basic Rate']->roomTypes()->count())->toBe(4);

    $price = fn (RoomType $type): string => (string) Rate::query()->where('rate_plan_id', $plans['Basic Rate']->id)->where('room_type_id', $type->id)->where('date', '2026-08-13')->value('amount');
    expect([$price($types[0]), $price($types[1]), $price($types[2]), $price($types[3])])->toBe(['150.00', '178.00', '133.00', '128.00'])
        ->and(Rate::query()->where('rate_plan_id', $plans['Basic Rate']->id)->where('room_type_id', $types[0]->id)->orderByDesc('date')->value('date')->toDateString())->toBe('2027-12-31');
});

it('gives the property the date the hotel was created in wastays', function () {
    runSetupImport()->assertSuccessful();

    expect($this->property->fresh()->created_at->utc()->toDateTimeString())->toBe('2026-08-13 08:02:00');
});

it('turns on SST and keeps the tourism tax off', function () {
    runSetupImport()->assertSuccessful();

    $sst = Tax::query()->where('property_id', $this->property->id)->where('code', 'SST')->sole();
    $tourism = Tax::query()->where('property_id', $this->property->id)->whereHas('transactionCode', fn ($query) => $query->where('system_key', 'tourism_tax'))->sole();

    expect($sst->enabled)->toBeTrue()
        ->and($sst->rate)->toEqual('8.00')
        ->and($sst->is_inclusive)->toBeFalse()
        ->and($sst->registration_number)->toBe('W10-1234-56789012')
        ->and($tourism->enabled)->toBeFalse();
});

it('maps charge items, payment methods and every transaction code', function () {
    runSetupImport()->assertSuccessful();

    $property = $this->property;
    $items = $property->chargeItems()->get()->keyBy('name');

    expect($items->has('Cleaning Fee'))->toBeTrue()
        ->and($items->has('Miscellaneous Revenue'))->toBeTrue()
        ->and($items->has('Extra Bed'))->toBeTrue()
        ->and($items['Parking']->active)->toBeFalse()
        ->and($items['Cleaning Fee']->default_amount)->toBeNull()
        ->and($property->chargeItems()->whereRaw("lower(name) like '%food%'")->count())->toBe(1);

    $link = fn (string $table, int $id) => ImportLink::query()->forSource($property->id, $table, (string) $id)->sole();
    $ota = PaymentMethod::query()->where('property_id', $property->id)->whereHas('transactionCode', fn ($query) => $query->where('system_key', 'ota_collected_payment'))->sole();

    expect($ota->active)->toBeTrue()
        ->and($link('transaction_codes', 50)->target_id)->toBe($ota->id)
        ->and($link('transaction_codes', 65)->target_id)->toBe($link('transaction_codes', 46)->target_id)
        ->and($link('transaction_codes', 49)->target_id)->toBe($link('transaction_codes', 47)->target_id)
        ->and($link('transaction_codes', 41)->target_type)->toBe(ChargeItem::class)
        ->and($link('transaction_codes', 64)->target_id)->toBe($items['TTX']->id)
        ->and($items['TTX']->transactionCode->applicableTaxes()->exists())->toBeFalse()
        ->and($link('transaction_codes', 38)->target_type)->toBe(Tax::class)
        ->and($link('transaction_codes', 37)->target_type)->toBe(Tax::class)
        ->and($link('transaction_codes', 53)->target_type)->toBe(TransactionCode::class)
        ->and(ImportLink::query()->forSource($property->id, 'transaction_codes', '43')->exists())->toBeFalse()
        ->and(ImportLink::query()->forSource($property->id, 'transaction_codes', '54')->exists())->toBeFalse();
});

it('prints counts and lists skipped items', function () {
    runSetupImport()
        ->expectsOutputToContain('Setup imported.')
        ->expectsOutputToContain('DBKK: hotel tax DBKK is not imported')
        ->expectsOutputToContain('Transaction codes: wastays code OTA_FEE has no lines')
        ->assertSuccessful();
});

it('creates nothing new when it runs again', function () {
    runSetupImport()->assertSuccessful();
    $before = setupSnapshot($this->property);

    runSetupImport()->expectsOutputToContain('Setup imported.')->assertSuccessful();

    expect(setupSnapshot($this->property))->toBe($before);
});

it('writes nothing on a dry run', function () {
    $before = setupSnapshot($this->property);

    runSetupImport(options: ['--dry-run' => true])->expectsOutputToContain('Dry run')->assertSuccessful();

    expect(setupSnapshot($this->property))->toBe($before)
        ->and($this->property->ratePlans()->sole()->name)->toBe('Basic Rate')
        ->and(Tax::query()->where('property_id', $this->property->id)->where('enabled', true)->exists())->toBeFalse();
});

it('stops and writes nothing when a code with lines has no estatell target', function () {
    $tables = wastaysSetupTables();
    $tables['folio_transactions'][] = ['id' => '4', 'transaction_code_id' => '43'];
    $tables['folio_transactions'][] = ['id' => '5', 'transaction_code_id' => '43'];
    $before = setupSnapshot($this->property);

    runSetupImport($tables)->expectsOutputToContain('OTA_FEE has 2 folio lines')->assertFailed();

    expect(setupSnapshot($this->property))->toBe($before);
});

it('replaces a different document prefix with the bundle hotel code, once', function () {
    $this->property->forceFill(['document_prefix' => 'ABC'])->save();

    runSetupImport()->expectsOutputToContain('Document prefix ABC → GEH')->assertSuccessful();

    expect($this->property->refresh()->document_prefix)->toBe('GEH')
        ->and($this->property->documentPrefixHistories()->where('prefix', 'GEH')->count())->toBe(1);

    runSetupImport()->doesntExpectOutputToContain('Document prefix')->assertSuccessful();

    expect($this->property->refresh()->document_prefix)->toBe('GEH');
});

it('leaves the prefix alone on a dry run', function () {
    $this->property->forceFill(['document_prefix' => 'ABC'])->save();

    runSetupImport(options: ['--dry-run' => true])->expectsOutputToContain('Document prefix ABC → GEH')->assertSuccessful();

    expect($this->property->refresh()->document_prefix)->toBe('ABC');
});

it('refuses a new prefix when the property has issued documents', function () {
    $this->property->forceFill(['document_prefix' => 'ABC'])->save();
    DocumentSequence::factory()->create(['property_id' => $this->property->id]);

    runSetupImport()->expectsOutputToContain('already issued documents')->assertFailed();

    expect($this->property->refresh()->document_prefix)->toBe('ABC')->and(RoomType::query()->count())->toBe(0);
});

it('refuses a prefix that another property holds', function () {
    $this->property->forceFill(['document_prefix' => 'ABC'])->save();
    Property::factory()->onboarding()->create(['document_prefix' => 'GEH']);

    runSetupImport()->expectsOutputToContain('Another property holds or held the prefix GEH')->assertFailed();

    expect($this->property->refresh()->document_prefix)->toBe('ABC')->and(RoomType::query()->count())->toBe(0);
});

/**
 * @return array<string, list<array<string, string>>>
 */
function wastaysTablesWithDetails(): array
{
    $tables = wastaysSetupTables();
    $tables['hotels'] = [[...$tables['hotels'][0], 'address' => 'Jalan Pantai 1', 'city' => 'Semporna', 'contact_phone' => '+60 89 781 000', 'contact_email' => 'info@hotel.test', 'ssm_number' => '202601000001', 'tin' => 'C1234567890']];

    return $tables;
}

it('fills the details and the check-in times of a property that has only an account', function () {
    $this->property->forceFill(['document_prefix' => 'XYZ'])->save();
    $this->property = Property::factory()->onboarding()->create(['document_prefix' => 'ABC', 'city' => null]);
    expect($this->property->settings()->exists())->toBeFalse();

    runSetupImport(wastaysTablesWithDetails(), ['--check-in' => '15:00', '--check-out' => '12:00'])->assertSuccessful();

    $this->property->refresh();

    expect($this->property->address_line1)->toBe('Jalan Pantai 1')
        ->and($this->property->city)->toBe('Semporna')
        ->and($this->property->contact_email)->toBe('info@hotel.test')
        ->and($this->property->registration_number)->toBe('202601000001')
        ->and($this->property->tin)->toBe('C1234567890')
        ->and(substr($this->property->settings->check_in_time, 0, 5))->toBe('15:00')
        ->and(substr($this->property->settings->check_out_time, 0, 5))->toBe('12:00')
        ->and(RoomType::query()->count())->toBe(4);
});

it('keeps what the owner already entered', function () {
    $this->property->forceFill(['address_line1' => 'Owner street', 'city' => 'Tawau'])->save();

    runSetupImport(wastaysTablesWithDetails())->assertSuccessful();

    $this->property->refresh();

    expect($this->property->address_line1)->toBe('Owner street')
        ->and($this->property->city)->toBe('Tawau')
        ->and($this->property->contact_email)->toBe('info@hotel.test');
});

it('refuses a time that is not HH:MM or only one of the two', function () {
    runSetupImport(options: ['--check-in' => '3pm', '--check-out' => '12:00'])->expectsOutputToContain('must be a time as HH:MM')->assertFailed();
    runSetupImport(options: ['--check-in' => '15:00'])->expectsOutputToContain('Give both')->assertFailed();
});

it('creates priced charge items for the Sand Bay codes', function () {
    $tables = wastaysSetupTables();
    $tables['transaction_codes'][] = ['id' => '201', 'code' => 'JETTY', 'name' => 'Tourist Jetty Fee', 'active' => 't'];
    $tables['transaction_codes'][] = ['id' => '202', 'code' => 'CB', 'name' => 'Charter Boat', 'active' => 't'];
    $tables['hotel_extra_charges'] = [
        ['id' => '14', 'transaction_code_id' => '201', 'position' => '8', 'pricing_type' => 'fixed', 'rate_value' => '10.0000', 'charging_unit' => 'per_person'],
        ['id' => '35', 'transaction_code_id' => '202', 'position' => '9', 'pricing_type' => 'fixed', 'rate_value' => '280.0000', 'charging_unit' => 'per_item'],
        ...$tables['hotel_extra_charges'],
    ];
    $tables['folio_transactions'][] = ['id' => '9', 'transaction_code_id' => '201'];

    runSetupImport($tables)->assertSuccessful();

    $jetty = ChargeItem::query()->where('property_id', $this->property->id)->where('name', 'Tourist Jetty Fee')->sole();
    $boat = ChargeItem::query()->where('property_id', $this->property->id)->where('name', 'Charter Boat')->sole();

    expect($jetty->default_amount)->toBe('10.00')
        ->and($jetty->charging_unit)->toBe(ChargingUnit::PerGuest)
        ->and($boat->default_amount)->toBe('280.00')
        ->and($boat->charging_unit)->toBe(ChargingUnit::PerItem)
        ->and(ImportLink::query()->where('source_table', 'transaction_codes')->where('source_id', '201')->exists())->toBeTrue();
});

/**
 * A Sand Bay shaped bundle: per-person plans repeated for each room type, an archived Walk-in, Base Rate for the room
 * type Standard Rate does not cover, an agent plan, a plan only for one company and an "except" plan with a named company.
 *
 * @return array<string, list<array<string, string>>>
 */
function wastaysOccupancyTables(): array
{
    $tables = wastaysSetupTables();
    $plan = fn (string $id, string $name, string $access = 'hidden', string $market = 'all', string $archived = ''): array => [
        'id' => $id, 'name' => $name, 'sell_mode' => 'per_person', 'archived_at' => $archived, 'ta_access' => $access, 'ta_market' => $market,
    ];
    $tables['rate_plans'] = [
        $plan('10', 'Standard Rate'), $plan('11', 'Base Rate'), $plan('12', 'Malaysian Agent Rate', 'all', 'local'),
        $plan('13', 'Super Sightseeing', 'only'), $plan('14', 'CU World Travel', 'except'), $plan('15', 'Walk-in', archived: '2026-10-01 00:00:00'),
    ];
    $tables['room_type_rate_plans'] = array_map(fn (array $row): array => ['id' => $row[0], 'room_type_id' => $row[1], 'rate_plan_id' => $row[2]], [
        ['100', '1', '10'], ['101', '2', '10'], ['102', '3', '11'], ['103', '1', '12'], ['104', '1', '13'], ['105', '1', '14'], ['106', '1', '15'],
    ]);
    $tables['room_type_rate_plan_occupancy_prices'] = [];

    foreach (['100' => 600, '101' => 700, '102' => 525, '103' => 500, '104' => 400, '105' => 450, '106' => 300] as $linkId => $one) {
        foreach ([1, 2] as $adults) {
            $tables['room_type_rate_plan_occupancy_prices'][] = ['id' => $linkId.$adults, 'room_type_rate_plan_id' => $linkId, 'adults' => (string) $adults, 'price' => number_format($one * $adults, 2, '.', '')];
        }
    }

    $tables['rate_plan_age_bands'] = [
        ['id' => '1', 'rate_plan_id' => '10', 'min_age' => '0', 'max_age' => '3', 'price_value' => '0.00', 'pricing_mode' => 'amount'],
        ['id' => '2', 'rate_plan_id' => '10', 'min_age' => '4', 'max_age' => '12', 'price_value' => '0.00', 'pricing_mode' => 'amount'],
        ['id' => '3', 'rate_plan_id' => '12', 'min_age' => '0', 'max_age' => '3', 'price_value' => '0.00', 'pricing_mode' => 'multiplier'],
        ['id' => '4', 'rate_plan_id' => '12', 'min_age' => '4', 'max_age' => '12', 'price_value' => '50.00', 'pricing_mode' => 'multiplier'],
    ];
    $tables['rate_plan_stay_discounts'] = [['id' => '1', 'rate_plan_id' => '12', 'min_nights' => '2', 'discount_type' => 'amount', 'value' => '100.00', 'from_night' => '2']];
    $tables['rate_plan_agency_rules'] = [
        ['id' => '1', 'rate_plan_id' => '13', 'hotel_corporate_account_id' => '8'],
        ['id' => '2', 'rate_plan_id' => '14', 'hotel_corporate_account_id' => '9'],
    ];

    return $tables;
}

it('merges the copies of each per-person plan into one per-guest plan with its prices, children, discounts and access', function () {
    runSetupImport(wastaysOccupancyTables())->assertSuccessful();

    $plans = RatePlan::query()->where('property_id', $this->property->id)->active()->get()->keyBy('name');

    expect($plans->keys()->sort()->values()->all())->toBe(['Basic Rate', 'CU World Travel', 'Malaysian Agent Rate', 'Super Sightseeing'])
        ->and($plans['Basic Rate']->pricing_basis)->toBe(PricingBasis::PerGuest)
        ->and($plans['Basic Rate']->roomTypes()->count())->toBe(4)
        ->and($plans['Basic Rate']->company_access)->toBe(CompanyAccess::Everyone)
        ->and($plans['Malaysian Agent Rate']->company_access)->toBe(CompanyAccess::All)
        ->and($plans['Malaysian Agent Rate']->company_market)->toBe(CompanyRateMarket::Local)
        ->and($plans['Super Sightseeing']->company_access)->toBe(CompanyAccess::Selected)
        ->and($plans['CU World Travel']->company_access)->toBe(CompanyAccess::Selected);

    $baseRoomType = ImportLink::query()->where('property_id', $this->property->id)->where('source_table', 'room_types')->where('source_id', '3')->value('target_id');
    $rate = Rate::query()->where('rate_plan_id', $plans['Basic Rate']->id)->where('room_type_id', $baseRoomType)->whereDate('date', '2026-08-13')->sole();

    expect($rate->amount)->toBe('525.00')
        ->and(DB::table('rate_occupancy_prices')->where('rate_id', $rate->id)->orderBy('adults')->pluck('amount', 'adults')->all())->toBe([1 => '525.00', 2 => '1050.00']);

    $children = $plans['Malaysian Agent Rate']->childPrices()->get()->sortBy('property_age_group_id')->values();
    $standardChildren = $plans['Basic Rate']->childPrices()->get()->sortBy('property_age_group_id')->values();

    expect($children->pluck('pricing')->map->value->all())->toBe(['free', 'percent'])
        ->and($children[1]->value)->toBe('50.00')
        ->and($standardChildren->pluck('pricing')->map->value->all())->toBe(['free', 'free'])
        ->and($plans['Malaysian Agent Rate']->stayDiscounts()->sole()->value)->toBe('100.00');
});

it('imports the per-person plans once', function () {
    runSetupImport(wastaysOccupancyTables())->assertSuccessful();
    $rates = Rate::query()->count();
    $plans = RatePlan::query()->count();

    runSetupImport(wastaysOccupancyTables())->assertSuccessful();

    expect(Rate::query()->count())->toBe($rates)->and(RatePlan::query()->count())->toBe($plans);
});

it('answers the Taxes step when the hotel has SST off and no tax is on', function () {
    $tables = wastaysOccupancyTables();
    $tables['hotels'] = [['id' => '5', 'created_at' => '2026-09-16T08:00:00Z', 'sst_enabled' => 'f', 'address' => 'Jalan Pantai 1', 'city' => 'Semporna']];
    Tax::query()->where('property_id', $this->property->id)->update(['enabled' => false]);

    runSetupImport($tables)->assertSuccessful();

    expect(OnboardingSkip::query()->where('property_id', $this->property->id)->where('step', OnboardingStep::Taxes)->exists())->toBeTrue();
});

/**
 * A Hotel Asia City shaped bundle: per-room plans repeated for each room type, a half day plan with hours, a staff
 * plan, an OTA plan with a price that is not fixed and an archived plan. Prices are before SST.
 *
 * @return array<string, list<array<string, string>>>
 */
function wastaysFixedTables(): array
{
    $tables = wastaysSetupTables();
    $tables['room_types'] = [
        ['id' => '1', 'name' => 'Deluxe Queen', 'base_price' => '93.22', 'max_adults' => '2', 'max_children' => '0'],
        ['id' => '2', 'name' => 'Superior Triple', 'base_price' => '118.65', 'max_adults' => '3', 'max_children' => '0'],
    ];
    $tables['rooms'] = [['id' => '1', 'number' => '101', 'room_type_id' => '1', 'archived_at' => '']];
    $plan = fn (string $id, string $name, string $hours = '', string $archived = ''): array => [
        'id' => $id, 'name' => $name, 'sell_mode' => 'per_room', 'archived_at' => $archived, 'ta_access' => 'hidden', 'day_use_hours' => $hours,
    ];
    $tables['rate_plans'] = [
        $plan('1', 'Standard Rate'), $plan('2', 'Standard Rate'), $plan('3', 'HALFDAY', '4'), $plan('4', 'HALFDAY', '4'),
        $plan('5', 'STAFF'), $plan('6', 'STAFF'), $plan('7', 'OYO'), $plan('8', 'DayUse Rate', '4', '2026-10-06 00:00:00'),
    ];
    $link = fn (string $id, string $roomType, string $plan, string $mode = 'fixed', string $value = ''): array => [
        'id' => $id, 'room_type_id' => $roomType, 'rate_plan_id' => $plan, 'pricing_mode' => $mode, 'pricing_value' => $value,
    ];
    $tables['room_type_rate_plans'] = [
        $link('1', '1', '1'), $link('2', '2', '2'),
        $link('3', '1', '3', value: '50.85'), $link('4', '2', '4', value: '63.56'),
        $link('5', '1', '5', value: '76.27'), $link('6', '2', '6', value: '110.17'),
        $link('7', '1', '7', 'multiplier', '0.80'), $link('8', '1', '8', value: '50.85'),
    ];

    return $tables;
}

it('imports the other per-room plans with a fixed price for each room type', function () {
    runSetupImport(wastaysFixedTables(), ['--net-prices' => true])->assertSuccessful();

    $plans = RatePlan::query()->where('property_id', $this->property->id)->active()->get()->keyBy('name');
    $roomTypes = $this->property->roomTypes()->orderBy('id')->get();
    $price = fn (string $plan, RoomType $type): string => (string) Rate::query()->where('rate_plan_id', $plans[$plan]->id)->where('room_type_id', $type->id)->whereDate('date', '2026-08-13')->value('amount');

    expect($plans->keys()->sort()->values()->all())->toBe(['Basic Rate', 'Corporate Rate', 'HALFDAY', 'STAFF', 'Walk-in Rate'])
        ->and($plans['STAFF']->pricing_basis)->toBe(PricingBasis::PerRoom)
        ->and($plans['STAFF']->roomTypes()->count())->toBe(2)
        ->and([$price('STAFF', $roomTypes[0]), $price('STAFF', $roomTypes[1])])->toBe(['76.27', '110.17'])
        ->and([$price('Basic Rate', $roomTypes[0]), $price('Basic Rate', $roomTypes[1])])->toBe(['93.22', '118.65'])
        ->and($plans['HALFDAY']->pricing_basis)->toBe(PricingBasis::DayUse)
        ->and([$plans['HALFDAY']->day_use_min_hours, $plans['HALFDAY']->day_use_max_hours])->toBe([4, 4])
        ->and(DB::table('rate_hour_prices')->join('rates', 'rates.id', '=', 'rate_hour_prices.rate_id')->where('rates.rate_plan_id', $plans['HALFDAY']->id)->whereDate('rates.date', '2026-08-13')->orderBy('amount')->pluck('rate_hour_prices.amount', 'rate_hour_prices.hours')->all())->toBe([4 => '63.56']);
});

it('skips a plan whose price is not fixed and an archived plan, and says so', function () {
    runSetupImport(wastaysFixedTables(), ['--net-prices' => true])
        ->expectsOutputToContain('OYO has no room type with a fixed price')
        ->assertSuccessful();

    expect($this->property->ratePlans()->whereIn('name', ['OYO', 'DayUse Rate'])->exists())->toBeFalse();
});

it('imports the other per-room plans once', function () {
    runSetupImport(wastaysFixedTables(), ['--net-prices' => true])->assertSuccessful();
    $counts = setupSnapshot($this->property);

    runSetupImport(wastaysFixedTables(), ['--net-prices' => true])->assertSuccessful();

    expect(setupSnapshot($this->property))->toBe($counts);
});

function wastaysBasicRatePrice(array $options, string $sst): string
{
    $tables = wastaysSetupTables();
    $tables['hotels'] = [['id' => '3', 'created_at' => '2026-08-13T08:02:00.038624Z', 'sst_enabled' => $sst]];
    $tables['room_types'] = [['id' => '1', 'name' => 'Deluxe Queen', 'base_price' => '108.00', 'max_adults' => '2', 'max_children' => '0']];
    $tables['rooms'] = [];

    runSetupImport($tables, $options)->assertSuccessful();

    return (string) Rate::query()->whereIn('rate_plan_id', RatePlan::query()->where('property_id', test()->property->id)->where('name', 'Basic Rate')->pluck('id'))->whereDate('date', '2026-08-13')->value('amount');
}

it('divides a price that includes SST by it', function () {
    expect(wastaysBasicRatePrice([], 't'))->toBe('100.00');
});

it('keeps a price that is before SST', function () {
    expect(wastaysBasicRatePrice(['--net-prices' => true], 't'))->toBe('108.00');
});

it('keeps the price of a hotel with SST off', function () {
    expect(wastaysBasicRatePrice([], 'f'))->toBe('108.00');
});
