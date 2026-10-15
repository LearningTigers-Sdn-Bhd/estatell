<?php

use App\Actions\Property\SeedPropertyDefaults;
use App\Enums\OnboardingStep;
use App\Enums\PaymentMethodType;
use App\Enums\PropertyType;
use App\Enums\StepState;
use App\Enums\SurchargeType;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\User;
use App\Services\Onboarding\Checks\PaymentMethodsCheck;
use App\Services\Property\SettingsLock;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/OnboardingSetup.php';

beforeEach(function () {
    $this->owner = User::factory()->owner()->for(Property::factory()->onboarding())->create();
    $this->property = $this->owner->property;
    completeOnboardingBasics($this->property);
    app(SeedPropertyDefaults::class)($this->property, PropertyType::Hotel, 'MY');
    $this->method = fn (PaymentMethodType $type) => $this->property->paymentMethods()->where('type', $type)->firstOrFail();
    $this->save = fn (array $rows) => $this->actingAs($this->owner)
        ->put(route('onboarding.payment-methods.update', $this->property->code), ['rows' => $rows]);
    $this->rowsOf = fn () => $this->property->paymentMethods()
        ->where('type', '!=', PaymentMethodType::OtaCollected)
        ->ordered()->get()
        ->map(fn (PaymentMethod $method): array => [
            'id' => $method->id,
            'name' => $method->name,
            'type' => null,
            'active' => $method->active,
            'requires_reference' => $method->requires_reference,
        ])->all();
});

function newMethodRow(string $name, array $overrides = []): array
{
    return [
        'id' => null,
        'name' => $name,
        'type' => 'e_wallet',
        'active' => true,
        'requires_reference' => true,
        ...$overrides,
    ];
}

test('the check is done with an active addable method and not started without one', function () {
    $check = new PaymentMethodsCheck;

    expect($check->state($this->property))->toBe(StepState::Done);

    $this->property->paymentMethods()
        ->whereIn('type', PaymentMethodType::addableCases())
        ->update(['active' => false]);

    expect($check->state($this->property))->toBe(StepState::NotStarted);

    ($this->method)(PaymentMethodType::DirectBill)->update(['active' => true]);

    expect($check->state($this->property))->toBe(StepState::NotStarted);
});

test('the page lists the methods without OTA collected and offers the addable types', function () {
    $this->actingAs($this->owner)
        ->get(route('onboarding.payment-methods.edit', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/payment-methods')
            ->has('paymentMethods', 5)
            ->where('paymentMethods', fn ($methods) => collect($methods)->pluck('type')->doesntContain('ota_collected'))
            ->where('paymentMethods', fn ($methods) => collect($methods)->firstWhere('type', 'direct_bill')['isEditable'] === false)
            ->has('typeOptions', 4)
            ->where('locked', false));
});

test('the page is a locked preview until Basics is done', function () {
    $property = Property::factory()->onboarding()->create();
    $owner = User::factory()->owner()->for($property)->create();

    $this->actingAs($owner)
        ->get(route('onboarding.payment-methods.edit', $property->code))
        ->assertInertia(fn (Assert $page) => $page->where('locked', true)->where('blockedBy.label', OnboardingStep::Basics->label()));

    $this->actingAs($owner)
        ->put(route('onboarding.payment-methods.update', $property->code), ['rows' => [newMethodRow('DuitNow QR')]])
        ->assertSessionHasErrors('onboarding');

    expect($property->paymentMethods()->count())->toBe(0);
});

test('saving turns a method off, renames it and keeps its code behaviour', function () {
    $card = ($this->method)(PaymentMethodType::Card);
    $rows = ($this->rowsOf)();
    $rows = array_map(fn (array $row): array => $row['id'] === $card->id
        ? [...$row, 'name' => 'Credit card', 'active' => false, 'requires_reference' => false]
        : $row, $rows);

    ($this->save)($rows)->assertRedirect(route('onboarding.companies.edit', $this->property->code));

    $card->refresh();

    expect($card->name)->toBe('Credit card')
        ->and($card->active)->toBeFalse()
        ->and($card->requires_reference)->toBeFalse()
        ->and($card->transactionCode->name)->toBe('Credit card')
        ->and($card->transactionCode->active)->toBeFalse();
});

test('saving adds a custom method last with a derived code and no surcharge', function () {
    ($this->save)([...($this->rowsOf)(), newMethodRow('DuitNow QR')])->assertSessionHasNoErrors();

    $duitnow = $this->property->paymentMethods()->where('name', 'DuitNow QR')->firstOrFail();

    expect($duitnow->type)->toBe(PaymentMethodType::EWallet)
        ->and($duitnow->active)->toBeTrue()
        ->and($duitnow->requires_reference)->toBeTrue()
        ->and($duitnow->surcharge_type)->toBe(SurchargeType::None)
        ->and($duitnow->surcharge_value)->toBeNull()
        ->and($duitnow->transactionCode->code)->toBe('DUITNOW_QR')
        ->and($duitnow->transactionCode->system_key)->toBeNull()
        ->and($duitnow->position)->toBeGreaterThan((int) $this->property->paymentMethods()->active()->whereKeyNot($duitnow->id)->max('position'));
});

test('a derived code gets a number when it is taken and stays within 20 characters', function () {
    ($this->save)([...($this->rowsOf)(), newMethodRow('Cash'.' ')])->assertSessionHasErrors('rows.5.name');

    ($this->save)([
        ...($this->rowsOf)(),
        newMethodRow('Touch n Go eWallet Number One'),
        newMethodRow('Touch n Go eWallet Number Two'),
    ])->assertSessionHasNoErrors();

    $codes = $this->property->paymentMethods()->where('name', 'like', 'Touch n Go%')->with('transactionCode')->get()
        ->map(fn (PaymentMethod $method): string => $method->transactionCode->code)->sort()->values()->all();

    expect($codes)->toBe(['TOUCH_N_GO_EWALLET_2', 'TOUCH_N_GO_EWALLET_N'])
        ->and(collect($codes)->every(fn (string $code): bool => strlen($code) <= 20))->toBeTrue();
});

test('a repeated save changes nothing', function () {
    ($this->save)([...($this->rowsOf)(), newMethodRow('DuitNow QR')])->assertSessionHasNoErrors();
    $count = $this->property->paymentMethods()->count();
    $codes = $this->property->transactionCodes()->count();

    ($this->save)(($this->rowsOf)())->assertSessionHasNoErrors();

    expect($this->property->paymentMethods()->count())->toBe($count)
        ->and($this->property->transactionCodes()->count())->toBe($codes);
});

test('the type of a saved method never changes', function () {
    $cash = ($this->method)(PaymentMethodType::Cash);
    $rows = array_map(fn (array $row): array => $row['id'] === $cash->id ? [...$row, 'type' => 'card'] : $row, ($this->rowsOf)());

    ($this->save)($rows)->assertSessionHasNoErrors();

    expect($cash->refresh()->type)->toBe(PaymentMethodType::Cash);
});

test('City ledger keeps its off state and reference rule but can be renamed', function () {
    $ledger = ($this->method)(PaymentMethodType::DirectBill);
    $rows = array_map(fn (array $row): array => $row['id'] === $ledger->id
        ? [...$row, 'name' => 'Company bills', 'active' => true, 'requires_reference' => true]
        : $row, ($this->rowsOf)());

    ($this->save)($rows)->assertSessionHasNoErrors();

    $ledger->refresh();

    expect($ledger->name)->toBe('Company bills')
        ->and($ledger->active)->toBeFalse()
        ->and($ledger->requires_reference)->toBeFalse();
});

test('a save that turns every addable method off is refused', function () {
    $rows = array_map(fn (array $row): array => [...$row, 'active' => false], ($this->rowsOf)());

    ($this->save)($rows)->assertSessionHasErrors('rows');

    expect($this->property->paymentMethods()->active()->count())->toBe(4);
});

test('a new row needs a type and only an addable type', function () {
    ($this->save)([...($this->rowsOf)(), newMethodRow('Voucher', ['type' => null])])->assertSessionHasErrors('rows.5.type');
    ($this->save)([...($this->rowsOf)(), newMethodRow('Voucher', ['type' => 'direct_bill'])])->assertSessionHasErrors('rows.5.type');
    ($this->save)([...($this->rowsOf)(), newMethodRow('Voucher', ['type' => 'ota_collected'])])->assertSessionHasErrors('rows.5.type');

    expect($this->property->paymentMethods()->where('name', 'Voucher')->exists())->toBeFalse();
});

test('names must be unique in the table and against OTA collected', function () {
    $ota = ($this->method)(PaymentMethodType::OtaCollected);

    ($this->save)([...($this->rowsOf)(), newMethodRow('DuitNow QR'), newMethodRow('duitnow qr')])->assertSessionHasErrors('rows.6.name');
    ($this->save)([...($this->rowsOf)(), newMethodRow($ota->name)])->assertSessionHasErrors('rows.5.name');
    ($this->save)([...($this->rowsOf)(), newMethodRow('')])->assertSessionHasErrors('rows.5.name');
});

test('a row cannot point at OTA collected or another property method', function () {
    $other = PaymentMethod::query()->where('property_id', '!=', $this->property->id)->first()
        ?? tap(Property::factory()->onboarding()->create(), function (Property $property) {
            completeOnboardingBasics($property);
            app(SeedPropertyDefaults::class)($property, PropertyType::Hotel, 'MY');
        })->paymentMethods()->firstOrFail();
    $ota = ($this->method)(PaymentMethodType::OtaCollected);

    ($this->save)([...($this->rowsOf)(), ['id' => $ota->id, 'name' => 'Hacked', 'type' => null, 'active' => true, 'requires_reference' => false]])
        ->assertSessionHasErrors('rows.5.id');
    ($this->save)([...($this->rowsOf)(), ['id' => $other->id, 'name' => 'Hacked', 'type' => null, 'active' => true, 'requires_reference' => false]])
        ->assertSessionHasErrors('rows.5.id');

    expect($ota->refresh()->name)->not->toBe('Hacked')
        ->and($other->refresh()->name)->not->toBe('Hacked');
});

test('a staff member without owner access cannot reach the step', function () {
    $staff = User::factory()->for($this->property)->withPermissions([])->create();

    $this->actingAs($staff)
        ->put(route('onboarding.payment-methods.update', $this->property->code), ['rows' => [newMethodRow('DuitNow QR')]])
        ->assertRedirect();

    expect($this->property->paymentMethods()->where('name', 'DuitNow QR')->exists())->toBeFalse();
});

test('saving the payment methods does not lock the country', function () {
    ($this->save)(($this->rowsOf)())->assertSessionHasNoErrors();

    expect(app(SettingsLock::class)->locksCountry($this->property))->toBeFalse();
});
