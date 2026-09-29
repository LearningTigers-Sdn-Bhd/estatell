<?php

use App\Actions\Catalogues\SeedTaxes;
use App\Actions\Catalogues\SeedTransactionCodes;
use App\Enums\Currency;
use App\Enums\DefaultRoleEnum;
use App\Enums\SystemTransactionCode;
use App\Enums\TaxRateType;
use App\Enums\TransactionCategory;
use App\Enums\TransactionKind;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/** @param array<string, mixed> $overrides */
function taxPayload(array $codeIds, array $overrides = []): array
{
    return array_merge([
        'name' => 'Heritage levy',
        'code' => 'HERITAGE',
        'rate_type' => TaxRateType::Flat->value,
        'rate' => '3.00',
        'is_inclusive' => false,
        'foreign_guests_only' => false,
        'registration_number' => ' dbkk 123 ',
        'enabled' => true,
        'applicable_transaction_code_ids' => $codeIds,
    ], $overrides);
}

test('tax rate types expose labels and options', function () {
    expect(TaxRateType::options())->toBe([
        ['value' => 'percent', 'label' => 'Percentage'],
        ['value' => 'flat', 'label' => 'Flat amount'],
    ]);
});

test('Malaysia properties receive disabled tax presets idempotently', function () {
    $property = Property::factory()->create(['country_code' => 'MY']);
    app(SeedTransactionCodes::class)($property, 'MY');

    app(SeedTaxes::class)($property, 'MY');
    $sst = $property->taxes()->where('code', 'SST')->sole();
    $sst->update(['name' => 'Accommodation SST', 'rate' => '9.00', 'enabled' => true]);
    app(SeedTaxes::class)($property, 'MY');

    expect($property->taxes()->count())->toBe(2)
        ->and($sst->refresh()->name)->toBe('Accommodation SST')
        ->and($sst->rate)->toBe('9.00')
        ->and($sst->enabled)->toBeTrue()
        ->and($sst->transactionCode->system_key)->toBe(SystemTransactionCode::SstTax->value)
        ->and($sst->applicableTransactionCodes()->sole()->system_key)->toBe(SystemTransactionCode::RoomRevenue->value);

    $tourism = $property->taxes()->where('code', 'TTX')->sole();
    expect($tourism->rate_type)->toBe(TaxRateType::Flat)
        ->and($tourism->rate)->toBe('10.00')
        ->and($tourism->foreign_guests_only)->toBeTrue()
        ->and($tourism->enabled)->toBeFalse();
});

test('unsupported countries receive no tax presets', function () {
    $property = Property::factory()->create();

    expect(fn () => app(SeedTaxes::class)($property, 'SG'))
        ->toThrow(InvalidArgumentException::class);

    expect($property->taxes()->count())->toBe(0);
});

test('the page loads the bounded catalogue and picker data', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create(['currency' => Currency::Myr]);
    $activeCode = TransactionCode::factory()->for($property)->create(['code' => 'ROOM', 'name' => 'Room revenue']);
    $inactiveCode = TransactionCode::factory()->for($property)->inactive()->create(['code' => 'OLD', 'name' => 'Old charge']);
    $tax = Tax::factory()->for($property)->create(['name' => 'SST']);
    $tax->applicableTransactionCodes()->attach([$activeCode->id, $inactiveCode->id]);
    TransactionCode::factory()->for($property)->create(['kind' => TransactionKind::Payment]);
    Tax::factory()->create(['name' => 'Another property tax']);

    $this->actingAs($owner)
        ->get(route('settings.taxes.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/taxes/index')
            ->has('taxes', 1)
            ->where('taxes.0.name', 'SST')
            ->where('taxes.0.applicableTransactionCodeIds', [$activeCode->id, $inactiveCode->id])
            ->where('transactionCodes', fn ($codes) => collect($codes)->pluck('label')->all() === ['ROOM — Room revenue', 'OLD — Old charge'])
            ->where('rateTypes', TaxRateType::options())
            ->where('currency', Currency::Myr->value)
            ->where('navigation.contexts.1.groups.2.items.0.title', 'Taxes')
            ->where('navigation.contexts.1.groups.2.items.0.active', true));
});

test('owners create a tax with its posting code and normalized values', function () {
    $owner = User::factory()->owner()->create();
    $chargeCode = TransactionCode::factory()->for($owner->property)->create();

    $this->actingAs($owner)
        ->post(route('settings.taxes.store', $owner->property->code), taxPayload([$chargeCode->id], [
            'name' => ' Heritage levy ',
            'code' => 'heritage-fee',
        ]))
        ->assertRedirect(route('settings.taxes.index', $owner->property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Heritage levy created.']);

    $tax = Tax::query()->where('code', 'HERITAGE_FEE')->sole();
    expect($tax->property_id)->toBe($owner->property->id)
        ->and($tax->registration_number)->toBe('DBKK 123')
        ->and($tax->transactionCode->only(['code', 'name', 'kind', 'category', 'active']))->toBe([
            'code' => 'TAX_HERITAGE_FEE',
            'name' => 'Heritage levy',
            'kind' => TransactionKind::Tax,
            'category' => TransactionCategory::Tax,
            'active' => true,
        ])
        ->and($tax->applicableTransactionCodes()->sole()->is($chargeCode))->toBeTrue()
        ->and(Activity::query()->where('event', 'tax.created')->count())->toBe(1);
});

test('tax validation protects identity rate applicability and enabled setup', function (array $overrides, string $field) {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $chargeCode = TransactionCode::factory()->for($property)->create();
    Tax::factory()->for($property)->create(['name' => 'Heritage levy', 'code' => 'HERITAGE']);

    $this->actingAs($owner)
        ->post(route('settings.taxes.store', $property->code), taxPayload([$chargeCode->id], $overrides))
        ->assertSessionHasErrors($field);
})->with([
    'name is unique ignoring case' => [['name' => 'HERITAGE LEVY', 'code' => 'OTHER'], 'name'],
    'code is unique ignoring case' => [['name' => 'Other', 'code' => 'heritage'], 'code'],
    'rate is positive' => [['name' => 'Other', 'code' => 'OTHER', 'rate' => '0'], 'rate'],
    'percentage is at most one hundred' => [['name' => 'Other', 'code' => 'OTHER', 'rate_type' => 'percent', 'rate' => '100.01'], 'rate'],
    'enabled tax needs applicability' => [['name' => 'Other', 'code' => 'OTHER', 'applicable_transaction_code_ids' => []], 'applicable_transaction_code_ids'],
]);

test('applicable codes must be active charge codes from the same property', function (string $case) {
    $owner = User::factory()->owner()->create();
    $code = match ($case) {
        'inactive' => TransactionCode::factory()->for($owner->property)->inactive()->create(),
        'tax' => TransactionCode::factory()->for($owner->property)->create(['kind' => TransactionKind::Tax]),
        'another property' => TransactionCode::factory()->create(),
    };

    $this->actingAs($owner)
        ->post(route('settings.taxes.store', $owner->property->code), taxPayload([$code->id]))
        ->assertSessionHasErrors('applicable_transaction_code_ids');
})->with(['inactive', 'tax', 'another property']);

test('disabled drafts may omit applicability and keep posting codes active', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->post(route('settings.taxes.store', $owner->property->code), taxPayload([], ['enabled' => false]))
        ->assertSessionHasNoErrors();

    $tax = Tax::query()->sole();
    expect($tax->enabled)->toBeFalse()
        ->and($tax->transactionCode->active)->toBeTrue()
        ->and($tax->applicableTransactionCodes()->count())->toBe(0);
});

test('updates synchronize custom posting identity and preserve system posting codes', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $chargeCode = TransactionCode::factory()->for($property)->create();
    $custom = Tax::factory()->for($property)->create(['name' => 'Old levy', 'code' => 'OLD']);
    $custom->applicableTransactionCodes()->attach($chargeCode);
    $systemCode = TransactionCode::factory()->for($property)->system(SystemTransactionCode::SstTax)->create();
    $system = Tax::factory()->for($property)->for($systemCode)->create(['name' => 'SST', 'code' => 'SST']);
    $system->applicableTransactionCodes()->attach($chargeCode);

    $this->actingAs($owner)->put(
        route('settings.taxes.update', [$property->code, $custom]),
        taxPayload([$chargeCode->id], ['name' => 'City levy', 'code' => 'CITY', 'enabled' => false]),
    );
    $this->put(
        route('settings.taxes.update', [$property->code, $system]),
        taxPayload([$chargeCode->id], ['name' => 'Accommodation SST', 'code' => 'SERVICE']),
    );

    expect($custom->refresh()->transactionCode->only(['code', 'name', 'active']))->toBe([
        'code' => 'TAX_CITY', 'name' => 'City levy', 'active' => true,
    ])->and($system->refresh()->transactionCode->only(['code', 'name']))->toBe([
        'code' => 'SST', 'name' => 'Accommodation SST',
    ]);
});

test('an existing inactive applicable code may be retained but not newly selected', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $code = TransactionCode::factory()->for($property)->create();
    $tax = Tax::factory()->for($property)->create();
    $tax->applicableTransactionCodes()->attach($code);
    $code->update(['active' => false]);

    $this->actingAs($owner)
        ->put(route('settings.taxes.update', [$property->code, $tax]), taxPayload([$code->id], ['name' => $tax->name, 'code' => $tax->code]))
        ->assertSessionHasNoErrors();

    $this->post(route('settings.taxes.store', $property->code), taxPayload([$code->id], ['name' => 'New levy', 'code' => 'NEW']))
        ->assertSessionHasErrors('applicable_transaction_code_ids');
});

test('routes enforce settings permission and property scoping', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    $owner = User::factory()->owner()->create();
    $otherTax = Tax::factory()->create();
    $ownerCode = TransactionCode::factory()->for($owner->property)->create();

    $this->actingAs($frontDesk)->get(route('settings.taxes.index', $frontDesk->property->code))->assertForbidden();
    $this->post(route('settings.taxes.store', $frontDesk->property->code), taxPayload([$ownerCode->id]))->assertForbidden();
    $this->actingAs($owner)
        ->put(route('settings.taxes.update', [$owner->property->code, $otherTax]), taxPayload([$ownerCode->id]))
        ->assertNotFound();
});

test('the database enforces tax invariants', function (array $attributes) {
    $property = Property::factory()->create();
    $postingCode = TransactionCode::factory()->for($property)->create(['kind' => TransactionKind::Tax]);

    DB::table('taxes')->insert([
        'property_id' => $property->id,
        'transaction_code_id' => $postingCode->id,
        'name' => 'Service tax',
        'code' => 'SST',
        'rate_type' => 'percent',
        'rate' => 8,
        'is_inclusive' => false,
        'foreign_guests_only' => false,
        'registration_number' => null,
        'enabled' => false,
        'created_at' => now(),
        'updated_at' => now(),
        ...$attributes,
    ]);
})->throws(QueryException::class)->with([
    'known rate type' => [['rate_type' => 'ratio']],
    'positive rate' => [['rate' => 0]],
    'percentage maximum' => [['rate' => 101]],
    'normalized code' => [['code' => 'sst tax']],
    'trimmed name' => [['name' => ' Service tax']],
]);
