<?php

use App\Actions\Catalogues\SeedRoomRevenuePolicies;
use App\Actions\Catalogues\SeedTaxes;
use App\Actions\Catalogues\SeedTransactionCodes;
use App\Actions\Catalogues\UpdateDayUseTaxes;
use App\Enums\DefaultRoleEnum;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\RatePlan;
use App\Models\Tax;
use App\Models\User;
use App\Services\Property\CountryProfiles;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/** @return array{0: User, 1: Property} */
function dayUseRevenueSetup(bool $withPlan = true): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create();
    app(SeedTransactionCodes::class)($property, CountryProfiles::for('MY'));
    app(SeedTaxes::class)($property, CountryProfiles::for('MY'));
    app(SeedRoomRevenuePolicies::class)($property);
    $property->taxes()->update(['enabled' => true]);

    if ($withPlan) {
        RatePlan::factory()->for($property)->dayUse()->create();
    }

    return [$owner, $property];
}

function dayUseCodeTaxIds(Property $property): array
{
    return $property->transactionCodes()->where('system_key', 'day_use_revenue')->sole()->applicableTaxes()->pluck('taxes.id')->sort()->values()->all();
}

test('the Day use tab is available without a day use plan', function () {
    [$owner, $property] = dayUseRevenueSetup(withPlan: false);

    $this->actingAs($owner)->get(route('settings.room-revenue.day-use.show', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/room-revenue/day-use')
            ->where('taxIds', [])
            ->where('preview.localTotal', '100.00'));
    $this->actingAs($owner)->get(route('settings.room-revenue.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page->missing('hasDayUse'));
    $this->actingAs($owner)->get(route('settings.room-revenue.policies.show', [$property->code, 'no-show']))
        ->assertInertia(fn (Assert $page) => $page->missing('hasDayUse'));
});

test('the Day use tab shows the saved taxes and a sample posting preview', function () {
    [$owner, $property] = dayUseRevenueSetup();
    $sst = $property->taxes()->where('code', 'SST')->sole();
    app(UpdateDayUseTaxes::class)($property, $owner, [$sst->id]);

    $this->actingAs($owner)
        ->get(route('settings.room-revenue.day-use.show', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/room-revenue/day-use')
            ->has('taxes', 2)
            ->where('taxIds', [$sst->id])
            ->where('preview.sampleAmount', '100.00')
            ->where('preview.localTotal', '108.00'));
});

test('saving without a day use plan links the taxes to DAY_USE only and logs a real change once', function () {
    [$owner, $property] = dayUseRevenueSetup(withPlan: false);
    $sst = $property->taxes()->where('code', 'SST')->sole();

    $this->actingAs($owner)
        ->put(route('settings.room-revenue.day-use.update', $property->code), ['tax_ids' => [$sst->id]])
        ->assertRedirect(route('settings.room-revenue.day-use.show', $property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Day use taxes saved.']);

    $this->actingAs($owner)->put(route('settings.room-revenue.day-use.update', $property->code), ['tax_ids' => [$sst->id]]);

    expect(dayUseCodeTaxIds($property))->toBe([$sst->id])
        ->and($property->transactionCodes()->where('system_key', 'room_revenue')->sole()->applicableTaxes()->count())->toBe(2)
        ->and(Activity::query()->where('event', 'day_use.taxes_updated')->count())->toBe(1);
});

test('the preview without a day use plan calculates an unsaved selection without saving it', function () {
    [$owner, $property] = dayUseRevenueSetup(withPlan: false);
    $tourism = $property->taxes()->where('code', 'TTX')->sole();

    $this->actingAs($owner)
        ->postJson(route('settings.room-revenue.day-use.preview', $property->code), ['tax_ids' => [$tourism->id]])
        ->assertOk()
        ->assertJsonPath('foreignTotal', '110.00');

    expect(dayUseCodeTaxIds($property))->toBe([]);
});

test('day use taxes must belong to the property', function () {
    [$owner, $property] = dayUseRevenueSetup();
    $foreign = Tax::factory()->create();

    $this->actingAs($owner)
        ->put(route('settings.room-revenue.day-use.update', $property->code), ['tax_ids' => [$foreign->id]])
        ->assertSessionHasErrors('tax_ids.0');
});

test('routes enforce settings permission and property scoping', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    [$owner] = dayUseRevenueSetup();
    $otherProperty = Property::factory()->create();

    $this->actingAs($frontDesk)->get(route('settings.room-revenue.day-use.show', $frontDesk->property->code))->assertForbidden();
    $this->put(route('settings.room-revenue.day-use.update', $frontDesk->property->code), ['tax_ids' => []])->assertForbidden();
    $this->postJson(route('settings.room-revenue.day-use.preview', $frontDesk->property->code), ['tax_ids' => []])->assertForbidden();
    $this->actingAs($owner)->get(route('settings.room-revenue.day-use.show', $otherProperty->code))->assertNotFound();
});
