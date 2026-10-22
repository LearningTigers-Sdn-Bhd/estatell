<?php

use App\Actions\Property\SeedPropertyDefaults;
use App\Enums\CompanyAccess;
use App\Enums\PropertyType;
use App\Models\ChargeItem;
use App\Models\ImportLink;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\User;
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
        'hotels' => [['id' => '3', 'sst_enabled' => 't', 'sst_registration_number' => 'W10-1234-56789012']],
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
    expect($plans->keys()->all())->toEqualCanonicalizing(['Standard Rate', 'Walk-in Rate', 'Corporate Rate'])
        ->and($plans['Walk-in Rate']->parent_rate_plan_id)->toBe($plans['Standard Rate']->id)
        ->and($plans['Corporate Rate']->company_access)->toBe(CompanyAccess::All)
        ->and($plans['Standard Rate']->roomTypes()->count())->toBe(4);

    $price = fn (RoomType $type): string => (string) Rate::query()->where('rate_plan_id', $plans['Standard Rate']->id)->where('room_type_id', $type->id)->where('date', '2026-08-13')->value('amount');
    expect([$price($types[0]), $price($types[1]), $price($types[2]), $price($types[3])])->toBe(['150.00', '178.00', '133.00', '128.00'])
        ->and(Rate::query()->where('rate_plan_id', $plans['Standard Rate']->id)->where('room_type_id', $types[0]->id)->orderByDesc('date')->value('date')->toDateString())->toBe('2027-12-31');
});

it('turns on SST and the tourism tax', function () {
    runSetupImport()->assertSuccessful();

    $sst = Tax::query()->where('property_id', $this->property->id)->where('code', 'SST')->sole();
    $tourism = Tax::query()->where('property_id', $this->property->id)->whereHas('transactionCode', fn ($query) => $query->where('system_key', 'tourism_tax'))->sole();

    expect($sst->enabled)->toBeTrue()
        ->and($sst->rate)->toEqual('8.00')
        ->and($sst->is_inclusive)->toBeFalse()
        ->and($sst->registration_number)->toBe('W10-1234-56789012')
        ->and($tourism->enabled)->toBeTrue();
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
        ->and($link('transaction_codes', 64)->target_id)->toBe($link('transaction_codes', 38)->target_id)
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

it('refuses a property that is not the bundle hotel', function () {
    $other = Property::factory()->onboarding()->create(['document_prefix' => 'ABC']);

    $this->artisan('wastays:import-setup', [
        'bundle' => wastaysBundleDir(wastaysSetupTables()),
        'property' => $other->code,
        '--actor' => $this->admin->email,
    ])->expectsOutputToContain('The bundle is for GEH')->assertFailed();

    expect(RoomType::query()->count())->toBe(0);
});
