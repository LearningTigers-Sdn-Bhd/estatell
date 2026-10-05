<?php

use App\Actions\Catalogues\SeedRoomRevenuePolicies;
use App\Actions\Catalogues\SeedTaxes;
use App\Actions\Catalogues\SeedTransactionCodes;
use App\Enums\Currency;
use App\Enums\DefaultRoleEnum;
use App\Enums\PolicyChargeMode;
use App\Enums\PolicyScope;
use App\Enums\SystemTransactionCode;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Tax;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function policyProperty(User $owner): Property
{
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create(['currency' => Currency::Myr]);
    app(SeedTransactionCodes::class)($property, 'MY');
    app(SeedTaxes::class)($property, 'MY');
    app(SeedRoomRevenuePolicies::class)($property);
    $property->taxes()->where('code', 'SST')->update(['enabled' => true]);
    $property->taxes()->where('code', 'TTX')->update(['enabled' => false]);
    $sst = $property->taxes()->where('code', 'SST')->sole();
    foreach ([SystemTransactionCode::RoomRevenue, ...SystemTransactionCode::policyCases()] as $code) {
        $property->transactionCodes()->where('system_key', $code->value)->sole()->applicableTaxes()->sync([$sst->id]);
    }

    return $property;
}

/** @return array<string, mixed> */
function policyInput(array $overrides = []): array
{
    return [
        'own_taxes' => false, 'charge_mode' => 'percentage', 'percent' => '50', 'percent_basis' => 'booked_rate', 'applies_to' => 'first_night',
        'text_en' => 'Half a night.', 'text_ms' => 'Separuh malam.', ...$overrides,
    ];
}

test('each policy tab renders its form, options and a free preview', function (string $slug, array $scopes) {
    $owner = User::factory()->owner()->create();
    $property = policyProperty($owner);

    $this->actingAs($owner)->get(route('settings.room-revenue.policies.show', [$property->code, $slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/room-revenue/policy')
            ->where('tab', $slug)
            ->where('policy.chargeMode', 'free')
            ->where('scopes', fn ($options) => collect($options)->pluck('value')->all() === $scopes)
            ->has('chargeModes', 3)
            ->has('rateBases', 2)
            ->where('locales.0.value', 'en')
            ->where('preview.sampleNight', '100.00')
            ->where('preview.lines.0.free', true)
            ->where('navigation.contexts.2.groups.3.items.3.active', true));
})->with([
    'no-show' => ['no-show', ['first_night', 'whole_stay']],
    'cancellation' => ['cancellation', ['first_night', 'whole_stay']],
    'early departure' => ['early-departure', ['unused_nights']],
    'late check-out' => ['late-checkout', ['one_charge']],
]);

test('an unknown tab is not found', function () {
    $owner = User::factory()->owner()->create();
    $property = policyProperty($owner);

    $this->actingAs($owner)->get(route('settings.room-revenue.policies.show', [$property->code, 'room']))->assertNotFound();
});

test('the preview charges the sample stay with the code taxes and does not save', function () {
    $owner = User::factory()->owner()->create();
    $property = policyProperty($owner);
    $url = route('settings.room-revenue.policies.preview', [$property->code, 'cancellation']);

    $this->actingAs($owner)->postJson($url, policyInput(['applies_to' => 'whole_stay', 'percent' => '100', 'free_until_days' => 2]))
        ->assertOk()
        ->assertJsonPath('lines.0.free', true)
        ->assertJsonPath('lines.1.free', false)
        ->assertJsonPath('lines.1.net', '300.00')
        ->assertJsonPath('lines.1.localTotal', '324.00')
        ->assertJsonPath('lines.1.foreignTotal', '324.00');

    expect($property->roomRevenuePolicies()->where('charge_mode', '!=', PolicyChargeMode::Free->value)->count())->toBe(0);
});

test('the early departure preview charges the unused nights and a fixed amount is one charge', function () {
    $owner = User::factory()->owner()->create();
    $property = policyProperty($owner);

    $this->actingAs($owner)->postJson(route('settings.room-revenue.policies.preview', [$property->code, 'early-departure']), policyInput())
        ->assertJsonPath('lines.0.net', '100.00');

    $this->postJson(route('settings.room-revenue.policies.preview', [$property->code, 'no-show']), ['own_taxes' => false, 'charge_mode' => 'fixed_amount', 'amount' => '40', 'applies_to' => 'whole_stay', 'text_en' => 'x', 'text_ms' => 'y'])
        ->assertJsonPath('lines.0.net', '40.00');
});

test('saving a policy stores it, flashes and redirects to its tab', function () {
    $owner = User::factory()->owner()->create();
    $property = policyProperty($owner);

    $this->actingAs($owner)->put(route('settings.room-revenue.policies.update', [$property->code, 'cancellation']), policyInput(['free_until_days' => 3]))
        ->assertRedirect(route('settings.room-revenue.policies.show', [$property->code, 'cancellation']))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Cancellation policy saved.']);

    $policy = $property->roomRevenuePolicies()->where('code', SystemTransactionCode::CancellationRevenue->value)->sole();

    expect($policy->charge_mode)->toBe(PolicyChargeMode::Percentage)
        ->and($policy->percent)->toBe('50.00')
        ->and($policy->applies_to)->toBe(PolicyScope::FirstNight)
        ->and($policy->free_until_days)->toBe(3)
        ->and($policy->text_ms)->toBe('Separuh malam.');
});

test('a tab with one scope sets it, and the free window is for cancellation only', function () {
    $owner = User::factory()->owner()->create();
    $property = policyProperty($owner);
    $input = policyInput(['free_until_days' => 5]);
    unset($input['applies_to']);

    $this->actingAs($owner)->put(route('settings.room-revenue.policies.update', [$property->code, 'late-checkout']), $input)->assertSessionHasNoErrors();

    $policy = $property->roomRevenuePolicies()->where('code', SystemTransactionCode::LateCheckoutRevenue->value)->sole();
    expect($policy->applies_to)->toBe(PolicyScope::OneCharge)->and($policy->free_until_days)->toBeNull();
});

test('the form is validated per charge mode and per tab', function (array $overrides, string $field) {
    $owner = User::factory()->owner()->create();
    $property = policyProperty($owner);

    $this->actingAs($owner)->put(route('settings.room-revenue.policies.update', [$property->code, 'no-show']), policyInput($overrides))
        ->assertSessionHasErrors($field);
})->with([
    'a scope the tab does not allow' => [['applies_to' => 'unused_nights'], 'applies_to'],
    'a percentage over 100' => [['percent' => '101'], 'percent'],
    'a percentage without a basis' => [['percent_basis' => null], 'percent_basis'],
    'a fixed amount that is zero' => [['charge_mode' => 'fixed_amount', 'amount' => '0'], 'amount'],
    'an empty text' => [['text_ms' => ''], 'text_ms'],
    'an unknown mode' => [['charge_mode' => 'half'], 'charge_mode'],
]);

test('policy routes enforce settings permission and property scoping', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    $owner = User::factory()->owner()->create();
    $otherProperty = Property::factory()->create();

    $this->actingAs($frontDesk)->get(route('settings.room-revenue.policies.show', [$frontDesk->property->code, 'no-show']))->assertForbidden();
    $this->put(route('settings.room-revenue.policies.update', [$frontDesk->property->code, 'no-show']), policyInput())->assertForbidden();
    $this->postJson(route('settings.room-revenue.policies.preview', [$frontDesk->property->code, 'no-show']), policyInput())->assertForbidden();

    $this->actingAs($owner)->get(route('settings.room-revenue.policies.show', [$otherProperty->code, 'no-show']))->assertNotFound();
});

function codeTaxIds(Property $property, SystemTransactionCode $code): array
{
    return $property->transactionCodes()->where('system_key', $code->value)->sole()->applicableTaxes()->pluck('taxes.id')->sort()->values()->all();
}

test('a policy with its own taxes links them to its code and the page shows them', function () {
    $owner = User::factory()->owner()->create();
    $property = policyProperty($owner);
    $sst = $property->taxes()->where('code', 'SST')->sole();
    $tourism = $property->taxes()->where('code', 'TTX')->sole();

    $this->actingAs($owner)->put(route('settings.room-revenue.policies.update', [$property->code, 'cancellation']), policyInput(['own_taxes' => true, 'tax_ids' => [$tourism->id]]))
        ->assertSessionHasNoErrors();

    expect(codeTaxIds($property, SystemTransactionCode::CancellationRevenue))->toBe([$tourism->id])
        ->and(codeTaxIds($property, SystemTransactionCode::NoShowRevenue))->toBe([$sst->id])
        ->and($property->roomRevenuePolicies()->where('code', SystemTransactionCode::CancellationRevenue->value)->sole()->own_taxes)->toBeTrue();
    $this->assertDatabaseHas('activity_log', ['event' => 'room_revenue_policy.updated']);

    $this->get(route('settings.room-revenue.policies.show', [$property->code, 'cancellation']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('policy.ownTaxes', true)
            ->where('policy.taxIds', [$tourism->id])
            ->where('roomTaxIds', [$sst->id])
            ->has('taxes', 2));
});

test('saving the Taxes tab keeps a policy with its own taxes and updates the rest', function () {
    $owner = User::factory()->owner()->create();
    $property = policyProperty($owner);
    $sst = $property->taxes()->where('code', 'SST')->sole();
    $tourism = $property->taxes()->where('code', 'TTX')->sole();
    $this->actingAs($owner)->put(route('settings.room-revenue.policies.update', [$property->code, 'cancellation']), policyInput(['own_taxes' => true, 'tax_ids' => []]));

    $this->put(route('settings.room-revenue.update', $property->code), ['tax_ids' => [$sst->id, $tourism->id]])->assertSessionHasNoErrors();

    expect(codeTaxIds($property, SystemTransactionCode::CancellationRevenue))->toBe([])
        ->and(codeTaxIds($property, SystemTransactionCode::RoomRevenue))->toBe([$sst->id, $tourism->id])
        ->and(codeTaxIds($property, SystemTransactionCode::NoShowRevenue))->toBe([$sst->id, $tourism->id]);
});

test('turning own taxes off links the code to the Taxes tab selection again', function () {
    $owner = User::factory()->owner()->create();
    $property = policyProperty($owner);
    $sst = $property->taxes()->where('code', 'SST')->sole();
    $url = route('settings.room-revenue.policies.update', [$property->code, 'no-show']);
    $this->actingAs($owner)->put($url, policyInput(['own_taxes' => true, 'tax_ids' => []]));
    expect(codeTaxIds($property, SystemTransactionCode::NoShowRevenue))->toBe([]);

    $this->put($url, policyInput(['own_taxes' => false, 'tax_ids' => [999]]))->assertSessionHasNoErrors();

    expect(codeTaxIds($property, SystemTransactionCode::NoShowRevenue))->toBe([$sst->id])
        ->and($property->roomRevenuePolicies()->where('code', SystemTransactionCode::NoShowRevenue->value)->sole()->own_taxes)->toBeFalse();
});

test('own taxes must be chosen from the property taxes', function (array $overrides, string $field) {
    $owner = User::factory()->owner()->create();
    $property = policyProperty($owner);
    $foreign = Tax::factory()->create();

    $input = policyInput($overrides);
    $input['tax_ids'] = ($overrides['tax_ids'] ?? null) === 'foreign' ? [$foreign->id] : ($overrides['tax_ids'] ?? null);
    if ($input['tax_ids'] === null) {
        unset($input['tax_ids']);
    }

    $this->actingAs($owner)->put(route('settings.room-revenue.policies.update', [$property->code, 'no-show']), $input)->assertSessionHasErrors($field);
})->with([
    'a tax of another property' => [['own_taxes' => true, 'tax_ids' => 'foreign'], 'tax_ids.0'],
    'no tax list' => [['own_taxes' => true], 'tax_ids'],
    'no own taxes flag' => [['own_taxes' => null], 'own_taxes'],
]);

test('the preview uses the unsaved own taxes', function () {
    $owner = User::factory()->owner()->create();
    $property = policyProperty($owner);
    $tourism = $property->taxes()->where('code', 'TTX')->sole();
    $tourism->update(['enabled' => true]);

    $this->actingAs($owner)->postJson(route('settings.room-revenue.policies.preview', [$property->code, 'no-show']), policyInput(['own_taxes' => true, 'tax_ids' => [$tourism->id], 'applies_to' => 'whole_stay', 'percent' => '100']))
        ->assertOk()
        ->assertJsonPath('lines.0.net', '300.00')
        ->assertJsonPath('lines.0.localTotal', '300.00')
        ->assertJsonPath('lines.0.foreignTotal', '310.00');
});
