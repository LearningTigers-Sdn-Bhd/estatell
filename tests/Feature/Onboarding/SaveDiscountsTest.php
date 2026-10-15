<?php

use App\Actions\Property\SeedPropertyDefaults;
use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Enums\OnboardingStep;
use App\Enums\PropertyType;
use App\Enums\StepState;
use App\Enums\SystemTransactionCode;
use App\Models\Discount;
use App\Models\Property;
use App\Models\User;
use App\Services\Onboarding\Checks\DiscountsCheck;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/OnboardingSetup.php';

beforeEach(function () {
    $this->owner = User::factory()->owner()->for(Property::factory()->onboarding())->create();
    $this->property = $this->owner->property;
    completeOnboardingBasics($this->property);
    app(SeedPropertyDefaults::class)($this->property, PropertyType::Hotel, 'MY');
    $this->starter = $this->property->discounts()->firstOrFail();
    $this->save = fn (array $rows) => $this->actingAs($this->owner)
        ->put(route('onboarding.discounts.update', $this->property->code), ['rows' => $rows]);
});

/** A row as the page sends it for a saved discount. */
function savedDiscountRow(Discount $discount, array $overrides = []): array
{
    return [
        'id' => $discount->id,
        'name' => $discount->name,
        'type' => $discount->type->value,
        'value' => $discount->value,
        'allow_override' => $discount->allow_override,
        'scope' => $discount->scope->value,
        'active' => $discount->active,
        'room_charges' => false,
        'transaction_code_ids' => [],
        ...$overrides,
    ];
}

function newDiscountRow(string $name, array $overrides = []): array
{
    return [
        'id' => null,
        'name' => $name,
        'type' => 'percent',
        'value' => '10',
        'allow_override' => false,
        'scope' => 'room_only',
        'active' => true,
        'room_charges' => false,
        'transaction_code_ids' => [],
        ...$overrides,
    ];
}

test('the check is done with an active discount and not started without one', function () {
    $check = new DiscountsCheck;

    expect($check->state($this->property))->toBe(StepState::Done);

    $this->starter->update(['active' => false]);

    expect($check->state($this->property))->toBe(StepState::NotStarted);
});

test('the page lists the starter discount and the charge items a Selected scope can pick', function () {
    $this->actingAs($this->owner)
        ->get(route('onboarding.discounts.edit', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/discounts')
            ->has('discounts', 1)
            ->where('discounts.0.isStarter', true)
            ->where('discounts.0.type', 'open')
            ->where('discounts.0.scope', 'all_charges')
            ->has('charges', 4)
            ->where('locked', false));
});

test('saving renames and turns off the starter discount without changing its code', function () {
    $code = $this->starter->transactionCode->code;

    ($this->save)([savedDiscountRow($this->starter, ['name' => 'Staff rate', 'active' => false])])
        ->assertSessionHasNoErrors();

    $this->starter->refresh();

    expect($this->property->discounts()->count())->toBe(1)
        ->and($this->starter->name)->toBe('Staff rate')
        ->and($this->starter->active)->toBeFalse()
        ->and($this->starter->transactionCode->code)->toBe($code);
});

test('a new row creates a discount with a code from its name and a number when taken', function () {
    ($this->save)([
        savedDiscountRow($this->starter),
        newDiscountRow('Walk-in', ['value' => '15']),
        newDiscountRow('Walk in', ['type' => 'fixed', 'value' => '20.00', 'allow_override' => true, 'scope' => 'all_charges']),
    ])->assertSessionHasNoErrors();

    $percent = $this->property->discounts()->where('name', 'Walk-in')->firstOrFail();
    $fixed = $this->property->discounts()->where('name', 'Walk in')->firstOrFail();

    expect($percent->transactionCode->code)->toBe('WALK_IN')
        ->and($percent->type)->toBe(DiscountType::Percent)
        ->and($percent->value)->toBe('15.00')
        ->and($percent->allow_override)->toBeFalse()
        ->and($percent->scope)->toBe(DiscountScope::RoomOnly)
        ->and($fixed->transactionCode->code)->toBe('WALK_IN_2')
        ->and($fixed->allow_override)->toBeTrue();
});

test('the type decides the value and the override', function () {
    ($this->save)([
        savedDiscountRow($this->starter),
        newDiscountRow('Open one', ['type' => 'open', 'value' => '50', 'allow_override' => false]),
        newDiscountRow('Percent one', ['type' => 'percent', 'value' => '10', 'allow_override' => true]),
    ])->assertSessionHasNoErrors();

    $open = $this->property->discounts()->where('name', 'Open one')->firstOrFail();
    $percent = $this->property->discounts()->where('name', 'Percent one')->firstOrFail();

    expect($open->value)->toBeNull()
        ->and($open->allow_override)->toBeTrue()
        ->and($percent->allow_override)->toBeFalse();
});

test('a Selected scope saves the room revenue codes for Room charges and the chosen charge items', function () {
    $laundry = $this->property->chargeItems()->where('name', 'Laundry')->firstOrFail();

    ($this->save)([
        savedDiscountRow($this->starter),
        newDiscountRow('Laundry deal', ['scope' => 'selected', 'room_charges' => true, 'transaction_code_ids' => [$laundry->transaction_code_id]]),
    ])->assertSessionHasNoErrors();

    $discount = $this->property->discounts()->where('name', 'Laundry deal')->firstOrFail();
    $roomIds = $this->property->transactionCodes()->whereIn('system_key', SystemTransactionCode::roomRevenueKeys())->pluck('id')->all();

    expect($discount->selectedTransactionCodes()->pluck('transaction_codes.id')->sort()->values()->all())
        ->toBe(collect([...$roomIds, $laundry->transaction_code_id])->sort()->values()->all());

    $this->actingAs($this->owner)
        ->get(route('onboarding.discounts.edit', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('discounts.1.roomCharges', true)
            ->where('discounts.1.transactionCodeIds', [$laundry->transaction_code_id]));
});

test('validation refuses bad rows without writing', function (array $row, string $field) {
    ($this->save)([savedDiscountRow($this->starter), newDiscountRow('Bad', $row)])
        ->assertSessionHasErrors($field);

    expect($this->property->discounts()->count())->toBe(1);
})->with([
    'percent above 100' => [['value' => '120'], 'rows.1.value'],
    'percent without value' => [['value' => null], 'rows.1.value'],
    'fixed of zero' => [['type' => 'fixed', 'value' => '0'], 'rows.1.value'],
    'selected with nothing chosen' => [['scope' => 'selected'], 'rows.1.transaction_code_ids'],
    'selected with a code of another property' => [['scope' => 'selected', 'transaction_code_ids' => [999999]], 'rows.1.transaction_code_ids'],
    'unknown type' => [['type' => 'free'], 'rows.1.type'],
]);

test('names must be unique ignoring case, among the rows and the saved discounts', function () {
    ($this->save)([
        savedDiscountRow($this->starter),
        newDiscountRow('Walk-in'),
        newDiscountRow('WALK-IN'),
    ])->assertSessionHasErrors('rows.2.name');

    ($this->save)([newDiscountRow('discount')])->assertSessionHasErrors('rows.0.name');
});

test('a discount of another property is refused', function () {
    $other = Discount::factory()->create();

    ($this->save)([savedDiscountRow($other)])->assertSessionHasErrors('rows.0.id');
});

test('the step is locked until Basics is done and a save is refused without writing', function () {
    $property = Property::factory()->onboarding()->create();
    $owner = User::factory()->owner()->for($property)->create();

    $this->actingAs($owner)
        ->get(route('onboarding.discounts.edit', $property->code))
        ->assertInertia(fn (Assert $page) => $page->where('locked', true)->where('blockedBy.label', OnboardingStep::Basics->label()));

    $this->actingAs($owner)
        ->put(route('onboarding.discounts.update', $property->code), ['rows' => [newDiscountRow('Walk-in')]])
        ->assertSessionHasErrors('onboarding');

    expect($property->discounts()->count())->toBe(0);
});

test('another property code returns 404', function () {
    $other = Property::factory()->onboarding()->create();

    $this->actingAs($this->owner)
        ->get(route('onboarding.discounts.edit', $other->code))
        ->assertNotFound();
});
