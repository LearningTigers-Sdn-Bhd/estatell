<?php

use App\Actions\Property\SeedPropertyDefaults;
use App\Enums\OnboardingStep;
use App\Enums\PropertyType;
use App\Enums\StepState;
use App\Enums\SystemTransactionCode;
use App\Models\OnboardingSkip;
use App\Models\Property;
use App\Models\Tax;
use App\Models\User;
use App\Services\Catalogues\RoomRevenueTaxIds;
use App\Services\Onboarding\Checks\TaxesCheck;
use App\Services\Onboarding\OnboardingProgress;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/OnboardingSetup.php';

beforeEach(function () {
    $this->owner = User::factory()->owner()->for(Property::factory()->onboarding())->create();
    $this->property = $this->owner->property;
    completeOnboardingBasics($this->property);
    app(SeedPropertyDefaults::class)($this->property, PropertyType::Hotel, 'MY');
    $this->sst = $this->property->taxes()->where('code', 'SST')->firstOrFail();
    $this->tourism = $this->property->taxes()->where('code', '!=', 'SST')->firstOrFail();
    $this->save = fn (array $payload) => $this->actingAs($this->owner)
        ->put(route('onboarding.taxes.update', $this->property->code), $payload);
});

function presetRow(Tax $tax, array $overrides = []): array
{
    return [
        'id' => $tax->id,
        'name' => $tax->name,
        'rate_type' => $tax->rate_type->value,
        'rate' => $tax->rate,
        'is_inclusive' => $tax->is_inclusive,
        'foreign_guests_only' => $tax->foreign_guests_only,
        'collect_at_check_in' => $tax->collect_at_check_in,
        'registration_number' => $tax->registration_number,
        'enabled' => $tax->enabled,
        'applies_to_rooms' => true,
        ...$overrides,
    ];
}

function customRow(string $name, array $overrides = []): array
{
    return [
        'id' => null,
        'name' => $name,
        'rate_type' => 'percent',
        'rate' => '10',
        'is_inclusive' => false,
        'foreign_guests_only' => false,
        'collect_at_check_in' => false,
        'registration_number' => null,
        'enabled' => true,
        'applies_to_rooms' => true,
        ...$overrides,
    ];
}

test('the check is done with any enabled tax and not started without one', function () {
    $check = new TaxesCheck;

    expect($check->state($this->property))->toBe(StepState::NotStarted);

    $this->sst->update(['enabled' => true]);

    expect($check->state($this->property))->toBe(StepState::Done);
});

test('the page lists the seeded Malaysian taxes, all turned off and applied to rooms', function () {
    $this->actingAs($this->owner)
        ->get(route('onboarding.taxes.edit', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/taxes')
            ->where('isMalaysia', true)
            ->where('noTax', false)
            ->where('locked', false)
            ->has('taxes', 2)
            ->where('taxes.0.enabled', false)
            ->where('taxes.0.isPreset', true)
            ->where('taxes.0.appliesToRooms', true));
});

test('turning on presets and choosing rooms saves the taxes and the room revenue links', function () {
    ($this->save)([
        'no_tax' => false,
        'rows' => [
            presetRow($this->sst, ['enabled' => true, 'registration_number' => ' w10-1808-32 ']),
            presetRow($this->tourism, ['enabled' => true, 'applies_to_rooms' => false]),
        ],
    ])->assertSessionHasNoErrors()->assertRedirect(route('onboarding.go-live.show', $this->property->code));

    expect($this->sst->refresh()->enabled)->toBeTrue()
        ->and($this->sst->registration_number)->toBe('W10-1808-32')
        ->and($this->tourism->refresh()->enabled)->toBeTrue()
        ->and(app(RoomRevenueTaxIds::class)->forRoom($this->property))->toBe([$this->sst->id])
        ->and(app(OnboardingProgress::class)->state($this->property, OnboardingStep::Taxes))->toBe(StepState::Done);
});

test('a new row creates a custom tax with a code derived from its name and a number when taken', function () {
    ($this->save)([
        'no_tax' => false,
        'rows' => [
            presetRow($this->sst),
            presetRow($this->tourism),
            customRow('VAT', ['rate' => '6', 'applies_to_rooms' => false]),
            customRow('Vat!', ['foreign_guests_only' => true, 'collect_at_check_in' => true]),
        ],
    ])->assertSessionHasNoErrors();

    $vat = $this->property->taxes()->where('name', 'VAT')->firstOrFail();
    $second = $this->property->taxes()->where('name', 'Vat!')->firstOrFail();

    expect($vat->code)->toBe('VAT')
        ->and($vat->rate)->toBe('6.00')
        ->and($vat->transactionCode->code)->toBe('TAX_VAT')
        ->and($second->code)->toBe('VAT_2')
        ->and($second->foreign_guests_only)->toBeTrue()
        ->and(app(RoomRevenueTaxIds::class)->forRoom($this->property))
        ->toBe(collect([$this->sst->id, $this->tourism->id, $second->id])->sort()->values()->all());
});

test('renaming a saved tax keeps its short code', function () {
    ($this->save)([
        'no_tax' => false,
        'rows' => [presetRow($this->sst, ['name' => 'Service Tax 8%']), presetRow($this->tourism)],
    ])->assertSessionHasNoErrors();

    expect($this->sst->refresh()->name)->toBe('Service Tax 8%')
        ->and($this->sst->code)->toBe('SST');
});

test('rows are validated one by one', function () {
    ($this->save)([
        'no_tax' => false,
        'rows' => [
            customRow('Service charge', ['rate' => '120']),
            customRow('service charge', ['rate' => '0']),
        ],
    ])->assertSessionHasErrors(['rows.0.rate', 'rows.1.name', 'rows.1.rate']);

    expect($this->property->taxes()->count())->toBe(2);
});

test('a name used by a saved tax without a row is refused', function () {
    ($this->save)([
        'no_tax' => false,
        'rows' => [customRow($this->sst->name)],
    ])->assertSessionHasErrors('rows.0.name');
});

test('we do not charge tax turns every tax off and skips the step', function () {
    $this->sst->update(['enabled' => true]);

    ($this->save)(['no_tax' => true])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('onboarding.go-live.show', $this->property->code));

    expect($this->property->taxes()->where('enabled', true)->count())->toBe(0)
        ->and(OnboardingSkip::query()->where('property_id', $this->property->id)->where('step', OnboardingStep::Taxes)->count())->toBe(1)
        ->and(app(OnboardingProgress::class)->state($this->property, OnboardingStep::Taxes))->toBe(StepState::Done);

    $this->actingAs($this->owner)
        ->get(route('onboarding.taxes.edit', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page->where('noTax', true));
});

test('saving rows after the answer was given removes the skip', function () {
    ($this->save)(['no_tax' => true])->assertSessionHasNoErrors();

    ($this->save)(['no_tax' => false, 'rows' => [presetRow($this->sst, ['enabled' => true]), presetRow($this->tourism)]])
        ->assertSessionHasNoErrors();

    expect(OnboardingSkip::query()->where('property_id', $this->property->id)->count())->toBe(0)
        ->and($this->sst->refresh()->enabled)->toBeTrue();
});

test('a save is refused while Basics is not done', function () {
    $other = User::factory()->owner()->for(Property::factory()->onboarding())->create();

    $this->actingAs($other)
        ->put(route('onboarding.taxes.update', $other->property->code), ['no_tax' => true])
        ->assertSessionHasErrors('onboarding');

    expect(OnboardingSkip::query()->where('property_id', $other->property->id)->count())->toBe(0);
});

test('the preview calculates the unsaved table for a local and a foreign guest', function () {
    $response = $this->actingAs($this->owner)
        ->postJson(route('onboarding.taxes.preview', $this->property->code), [
            'no_tax' => false,
            'rows' => [
                presetRow($this->sst, ['enabled' => true]),
                presetRow($this->tourism, ['enabled' => true]),
                customRow('Service charge', ['applies_to_rooms' => false]),
            ],
        ])->assertOk();

    expect($response->json('localTotal'))->toBe('108.00')
        ->and($response->json('foreignTotal'))->toBe('118.00')
        ->and($response->json('lines'))->toHaveCount(2);
    expect($this->sst->refresh()->enabled)->toBeFalse();
});

test('a custom tax can be removed but a preset cannot', function () {
    ($this->save)([
        'no_tax' => false,
        'rows' => [presetRow($this->sst), presetRow($this->tourism), customRow('VAT')],
    ])->assertSessionHasNoErrors();
    $vat = $this->property->taxes()->where('code', 'VAT')->firstOrFail();
    $codeId = $vat->transaction_code_id;

    $this->actingAs($this->owner)
        ->delete(route('onboarding.taxes.destroy', [$this->property->code, $this->sst->id]))
        ->assertRedirect();
    $this->actingAs($this->owner)
        ->delete(route('onboarding.taxes.destroy', [$this->property->code, $vat->id]))
        ->assertRedirect();

    expect($this->property->taxes()->whereKey($this->sst->id)->exists())->toBeTrue()
        ->and($this->property->taxes()->whereKey($vat->id)->exists())->toBeFalse()
        ->and($this->property->transactionCodes()->whereKey($codeId)->exists())->toBeFalse()
        ->and($this->property->transactionCodes()->where('system_key', SystemTransactionCode::RoomRevenue->value)->exists())->toBeTrue();
});

test('another property cannot remove or see this property taxes', function () {
    $other = User::factory()->owner()->for(Property::factory()->onboarding())->create();
    completeOnboardingBasics($other->property);

    $this->actingAs($other)
        ->delete(route('onboarding.taxes.destroy', [$other->property->code, $this->sst->id]))
        ->assertNotFound();
});

test('the page opens as a locked preview before Basics is saved, with no example', function () {
    $other = User::factory()->owner()->for(Property::factory()->onboarding())->create();

    $this->actingAs($other)
        ->get(route('onboarding.taxes.edit', $other->property->code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/taxes')
            ->where('locked', true)
            ->where('blockedBy.label', 'Basics')
            ->where('preview', null)
            ->where('currency', null));
});
