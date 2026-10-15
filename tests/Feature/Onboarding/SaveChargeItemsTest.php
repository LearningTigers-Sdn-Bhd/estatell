<?php

use App\Actions\Property\SeedPropertyDefaults;
use App\Enums\ChargingUnit;
use App\Enums\OnboardingStep;
use App\Enums\PropertyType;
use App\Enums\StepState;
use App\Enums\TransactionCategory;
use App\Models\ChargeItem;
use App\Models\OnboardingSkip;
use App\Models\Property;
use App\Models\User;
use App\Services\Onboarding\Checks\ChargeItemsCheck;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/OnboardingSetup.php';

beforeEach(function () {
    $this->owner = User::factory()->owner()->for(Property::factory()->onboarding())->create();
    $this->property = $this->owner->property;
    completeOnboardingBasics($this->property);
    app(SeedPropertyDefaults::class)($this->property, PropertyType::Hotel, 'MY');
    OnboardingSkip::factory()->for($this->property)->create(['step' => OnboardingStep::Taxes]);
    $this->sst = $this->property->taxes()->where('code', 'SST')->firstOrFail();
    $this->starter = fn (string $name) => $this->property->chargeItems()->where('name', $name)->firstOrFail();
    $this->save = fn (array $rows) => $this->actingAs($this->owner)
        ->put(route('onboarding.charge-items.update', $this->property->code), ['rows' => $rows]);
});

/** A row as the page sends it for a saved item. */
function savedChargeItemRow(ChargeItem $chargeItem, array $overrides = []): array
{
    return [
        'id' => $chargeItem->id,
        'name' => $chargeItem->name,
        'category' => $chargeItem->transactionCode->category->value,
        'default_amount' => $chargeItem->default_amount,
        'charging_unit' => $chargeItem->charging_unit->value,
        'allow_override' => $chargeItem->allow_override,
        'active' => $chargeItem->active,
        'tax_ids' => $chargeItem->transactionCode->applicableTaxes->pluck('id')->values()->all(),
        ...$overrides,
    ];
}

function newChargeItemRow(string $name, array $overrides = []): array
{
    return [
        'id' => null,
        'name' => $name,
        'category' => 'other',
        'default_amount' => null,
        'charging_unit' => 'per_item',
        'allow_override' => true,
        'active' => true,
        'tax_ids' => [],
        ...$overrides,
    ];
}

test('the check is not started with starter items only and done with a price or a tax', function () {
    $check = new ChargeItemsCheck;
    $breakfast = ($this->starter)('Laundry');

    expect($check->state($this->property))->toBe(StepState::NotStarted);

    $breakfast->update(['default_amount' => '10.00']);

    expect($check->state($this->property))->toBe(StepState::Done);

    $breakfast->update(['default_amount' => null, 'active' => false]);

    expect($check->state($this->property))->toBe(StepState::NotStarted);

    $breakfast->transactionCode->applicableTaxes()->attach($this->sst);

    $breakfast->update(['active' => true]);

    expect($check->state($this->property))->toBe(StepState::Done);
});

test('the step is locked until Taxes is done and a save is refused without writing', function () {
    $property = Property::factory()->onboarding()->create();
    $owner = User::factory()->owner()->for($property)->create();

    $this->actingAs($owner)
        ->get(route('onboarding.charge-items.edit', $property->code))
        ->assertInertia(fn (Assert $page) => $page->where('locked', true)->where('blockedBy.label', OnboardingStep::Taxes->label()));

    $this->actingAs($owner)
        ->put(route('onboarding.charge-items.update', $property->code), ['rows' => [newChargeItemRow('Extra bed')]])
        ->assertSessionHasErrors('onboarding');

    expect($property->chargeItems()->count())->toBe(0);
});

test('the page lists the starter items without Transport, and preselects only the percentage taxes for all guests', function () {
    $this->sst->update(['enabled' => true]);
    $this->property->taxes()->where('code', 'TTX')->update(['enabled' => true]);

    $this->actingAs($this->owner)
        ->get(route('onboarding.charge-items.edit', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/charge-items')
            ->has('chargeItems', 4)
            ->where('presetTaxIds', [$this->sst->id])
            ->where('locked', false)
            ->where('highlightTaxes', false));

    $this->actingAs($this->owner)
        ->get(route('onboarding.charge-items.edit', ['property' => $this->property->code, 'from' => 'taxes']))
        ->assertInertia(fn (Assert $page) => $page->where('highlightTaxes', true));
});

test('saving turns a starter item off and keeps it, and sets its price and taxes', function () {
    $laundry = ($this->starter)('Laundry');
    $breakfast = ($this->starter)('Food and beverage');

    ($this->save)([
        savedChargeItemRow($laundry, ['active' => false]),
        savedChargeItemRow($breakfast, ['default_amount' => '25.00', 'charging_unit' => ChargingUnit::PerGuestNight->value, 'tax_ids' => [$this->sst->id]]),
    ])->assertSessionHasNoErrors();

    $laundry->refresh();
    $breakfast->refresh();

    expect($this->property->chargeItems()->count())->toBe(4)
        ->and($laundry->active)->toBeFalse()
        ->and($breakfast->default_amount)->toBe('25.00')
        ->and($breakfast->charging_unit)->toBe(ChargingUnit::PerGuestNight)
        ->and($breakfast->transactionCode->applicableTaxes->pluck('id')->all())->toBe([$this->sst->id])
        ->and($breakfast->transactionCode->category)->toBe(TransactionCategory::FoodAndBeverage);
});

test('an item with no price always has Staff can change the price on', function () {
    $fee = ($this->starter)('General fee');

    ($this->save)([savedChargeItemRow($fee, ['default_amount' => null, 'allow_override' => false])])
        ->assertSessionHasNoErrors();

    expect($fee->refresh()->allow_override)->toBeTrue();
});

test('a new row gets a code from its name, a number when taken, and its category', function () {
    ($this->save)([
        ...collect($this->property->chargeItems()->get())->map(fn (ChargeItem $item) => savedChargeItemRow($item))->all(),
        newChargeItemRow('Extra bed', ['category' => TransactionCategory::Other->value, 'default_amount' => '80.00']),
        newChargeItemRow('Extra-bed', ['category' => TransactionCategory::Laundry->value]),
    ])->assertSessionHasNoErrors();

    $first = $this->property->chargeItems()->where('name', 'Extra bed')->firstOrFail();
    $second = $this->property->chargeItems()->where('name', 'Extra-bed')->firstOrFail();

    expect($first->transactionCode->code)->toBe('EXTRA_BED')
        ->and($first->default_amount)->toBe('80.00')
        ->and($first->transactionCode->category)->toBe(TransactionCategory::Other)
        ->and($second->transactionCode->code)->toBe('EXTRA_BED_2')
        ->and($second->transactionCode->category)->toBe(TransactionCategory::Laundry);
});

test('a new row needs a category that is not Transport', function () {
    ($this->save)([
        ...collect($this->property->chargeItems()->get())->map(fn (ChargeItem $item) => savedChargeItemRow($item))->all(),
        newChargeItemRow('Boat', ['category' => TransactionCategory::Transport->value]),
    ])->assertSessionHasErrors('rows.4.category');

    expect($this->property->chargeItems()->where('name', 'Boat')->exists())->toBeFalse();
});
