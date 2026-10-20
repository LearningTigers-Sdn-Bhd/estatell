<?php

use App\Actions\Property\SeedPropertyDefaults;
use App\Actions\Rates\ReconcileRoomTypeRatePlans;
use App\Enums\PricingBasis;
use App\Enums\PropertyType;
use App\Enums\RateDerivation;
use App\Models\Property;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Onboarding\Checks\BasicRateCheck;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/OnboardingSetup.php';
require_once __DIR__.'/../Support/BasicRateSetup.php';

beforeEach(function () {
    $this->owner = User::factory()->owner()->for(Property::factory()->onboarding())->create();
    $this->property = $this->owner->property;
    completeOnboardingBasics($this->property);
    app(SeedPropertyDefaults::class)($this->property, PropertyType::Hotel, 'MY');
    $this->property->taxes()->update(['enabled' => true]);
    $this->chalet = RoomType::factory()->for($this->property)->create(['name' => 'Garden Chalet']);
    $this->longhouse = RoomType::factory()->for($this->property)->create(['name' => 'Longhouse', 'max_adults' => 3, 'base_occupancy' => 3]);
    app(ReconcileRoomTypeRatePlans::class)($this->chalet);
    app(ReconcileRoomTypeRatePlans::class)($this->longhouse);
    $this->basic = fn (string $longhouseBy = 'per_room') => [
        basicRateRow($this->chalet),
        basicRateRow($this->longhouse, $longhouseBy, '100.00', '130.00'),
    ];
    $this->save = fn (array $moreRates, ?array $rows = null) => $this->actingAs($this->owner)
        ->put(route('onboarding.basic-rate.update', $this->property->code), basicRatePayload($rows ?? ($this->basic)(), ['more_rates' => $moreRates]));
    $this->planNamed = fn (string $name) => RatePlan::query()->where('property_id', $this->property->id)->active()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
});

function followerRow(array $overrides = []): array
{
    return [
        'key' => 'new-1',
        'plan_ids' => [],
        'name' => 'Room with breakfast',
        'mode' => 'follows',
        'parent_basis' => 'per_room',
        'direction' => 'up',
        'unit' => 'amount',
        'amount' => '30.00',
        'room_type_ids' => [],
        ...$overrides,
    ];
}

function ownRow(array $prices, array $overrides = []): array
{
    return [
        'key' => 'new-2',
        'plan_ids' => [],
        'name' => 'Long stay',
        'mode' => 'own',
        'prices' => $prices,
        ...$overrides,
    ];
}

test('more rates save with the unused empty arrays sent by the browser', function () {
    ($this->save)([
        followerRow(['room_type_ids' => [$this->chalet->id], 'prices' => []]),
        ownRow([
            ['room_type_id' => $this->longhouse->id, 'price_by' => 'per_room', 'weekday' => '150.00', 'weekend' => '170.00'],
        ], ['room_type_ids' => []]),
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseHas('rate_plans', ['property_id' => $this->property->id, 'name' => 'Room with breakfast']);
    $this->assertDatabaseHas('rate_plans', ['property_id' => $this->property->id, 'name' => 'Long stay']);
});

test('a follower is created under the Basic Rate plan with its change and room types', function () {
    ($this->save)([followerRow(['room_type_ids' => [$this->chalet->id]])])->assertSessionHasNoErrors();

    $plan = ($this->planNamed)('Room with breakfast');
    $parent = ($this->planNamed)('Basic Rate');

    expect($plan->parent_rate_plan_id)->toBe($parent->id)
        ->and($plan->derivation)->toBe(RateDerivation::Amount)
        ->and($plan->derivation_value)->toBe('30.00')
        ->and($plan->pricing_basis)->toBe(PricingBasis::PerRoom)
        ->and($plan->roomTypes()->pluck('room_types.id')->all())->toBe([$this->chalet->id])
        ->and(Rate::query()->where('rate_plan_id', $plan->id)->whereNotNull('amount')->count())->toBe(0);
});

test('a percent decrease and a follower of the Guest plan are saved with their sign and parent', function () {
    ($this->save)([
        followerRow(['name' => 'Early bird', 'unit' => 'percent', 'direction' => 'down', 'amount' => '10', 'room_type_ids' => [$this->chalet->id]]),
        followerRow(['key' => 'new-3', 'name' => 'Guest extra', 'parent_basis' => 'per_guest', 'room_type_ids' => [$this->longhouse->id]]),
    ], ($this->basic)('per_guest'))->assertSessionHasNoErrors();

    $early = ($this->planNamed)('Early bird');
    $guest = ($this->planNamed)('Guest extra');

    expect($early->derivation)->toBe(RateDerivation::Percent)
        ->and($early->derivation_value)->toBe('-10.00')
        ->and($early->parent_rate_plan_id)->toBe(($this->planNamed)('Basic Rate / Room')->id)
        ->and($guest->pricing_basis)->toBe(PricingBasis::PerGuest)
        ->and($guest->parent_rate_plan_id)->toBe(($this->planNamed)('Basic Rate / Guest')->id);
});

test('own prices save one plan for 365 days, or a Room and a Guest plan when both bases are used', function () {
    ($this->save)([ownRow([
        ['room_type_id' => $this->chalet->id, 'price_by' => 'per_room', 'weekday' => '150.00', 'weekend' => '170.00'],
    ])])->assertSessionHasNoErrors();

    $plan = ($this->planNamed)('Long stay');

    expect($plan->parent_rate_plan_id)->toBeNull()
        ->and(Rate::query()->where('rate_plan_id', $plan->id)->count())->toBe(365)
        ->and(rateOn($plan, $this->chalet, '2026-10-16'))->toBe('170.00')
        ->and(rateOn($plan, $this->chalet, '2026-10-14'))->toBe('150.00');

    ($this->save)([ownRow([
        ['room_type_id' => $this->chalet->id, 'price_by' => 'per_room', 'weekday' => '150.00', 'weekend' => '170.00'],
        ['room_type_id' => $this->longhouse->id, 'price_by' => 'per_guest', 'weekday' => '70.00', 'weekend' => '80.00'],
    ], ['plan_ids' => [$plan->id]])])->assertSessionHasNoErrors();

    expect(($this->planNamed)('Long stay'))->toBeNull()
        ->and(($this->planNamed)('Long stay / Room')->id)->toBe($plan->id)
        ->and(($this->planNamed)('Long stay / Guest')->pricing_basis)->toBe(PricingBasis::PerGuest);
});

test('the page reads follower and own price rows back, grouping the Room and Guest plans', function () {
    ($this->save)([
        followerRow(['room_type_ids' => [$this->chalet->id]]),
        ownRow([
            ['room_type_id' => $this->chalet->id, 'price_by' => 'per_room', 'weekday' => '150.00', 'weekend' => '170.00'],
            ['room_type_id' => $this->longhouse->id, 'price_by' => 'per_guest', 'weekday' => '70.00', 'weekend' => '80.00'],
        ]),
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->owner)
        ->get(route('onboarding.basic-rate.edit', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->has('moreRates', 2)
            ->where('moreRates.0.mode', 'follows')
            ->where('moreRates.0.name', 'Room with breakfast')
            ->where('moreRates.0.parentBasis', 'per_room')
            ->where('moreRates.0.direction', 'up')
            ->where('moreRates.0.unit', 'amount')
            ->where('moreRates.0.amount', '30.00')
            ->where('moreRates.0.roomTypeIds', [$this->chalet->id])
            ->where('moreRates.1.mode', 'own')
            ->where('moreRates.1.name', 'Long stay')
            ->has('moreRates.1.planIds', 2)
            ->has('moreRates.1.prices', 2)
            ->where('moreRates.1.prices.1.priceBy', 'per_guest')
            ->where('moreRates.1.prices.1.weekday', '70.00')
            ->has('preview.followers', 1)
            ->where('preview.followers.0.parentAmount', '180.00')
            ->where('preview.followers.0.result', '210.00'));
});

test('a saved row updates its plan in place and a dropped row archives its plan', function () {
    ($this->save)([followerRow(['room_type_ids' => [$this->chalet->id]]), followerRow(['key' => 'new-3', 'name' => 'Other', 'room_type_ids' => [$this->longhouse->id]])]);
    $plan = ($this->planNamed)('Room with breakfast');
    $other = ($this->planNamed)('Other');

    ($this->save)([followerRow(['plan_ids' => [$plan->id], 'name' => 'With breakfast', 'unit' => 'percent', 'direction' => 'down', 'amount' => '5', 'room_type_ids' => [$this->chalet->id, $this->longhouse->id]])])
        ->assertSessionHasNoErrors();

    $plan->refresh();

    expect($plan->name)->toBe('With breakfast')
        ->and($plan->derivation_value)->toBe('-5.00')
        ->and($plan->roomTypes()->count())->toBe(2)
        ->and($other->refresh()->archived_at)->not->toBeNull();
});

test('switching a row between Follows and Own prices archives the old plan and saves a new one', function () {
    ($this->save)([followerRow(['room_type_ids' => [$this->chalet->id]])]);
    $old = ($this->planNamed)('Room with breakfast');

    ($this->save)([ownRow([['room_type_id' => $this->chalet->id, 'price_by' => 'per_room', 'weekday' => '99.00', 'weekend' => '109.00']], ['name' => 'Room with breakfast', 'plan_ids' => [$old->id]])])
        ->assertSessionHasNoErrors();

    $new = ($this->planNamed)('Room with breakfast');

    expect($old->refresh()->archived_at)->not->toBeNull()
        ->and($new->id)->not->toBe($old->id)
        ->and($new->parent_rate_plan_id)->toBeNull()
        ->and(rateOn($new, $this->chalet, '2026-10-14'))->toBe('99.00');
});

test('dropping the follower lets the Basic Rate plan switch basis in the same save', function () {
    ($this->save)([followerRow(['room_type_ids' => [$this->chalet->id, $this->longhouse->id]])]);

    ($this->save)([], [basicRateRow($this->chalet, 'per_guest', '85.00', '95.00'), basicRateRow($this->longhouse, 'per_guest', '60.00', '70.00')])
        ->assertSessionHasNoErrors();

    expect(($this->planNamed)('Basic Rate')->pricing_basis)->toBe(PricingBasis::PerGuest)
        ->and(($this->planNamed)('Room with breakfast'))->toBeNull();
});

test('a follower whose Basic Rate basis is no longer used is refused', function () {
    ($this->save)([followerRow(['room_type_ids' => [$this->chalet->id]])]);
    $plan = ($this->planNamed)('Room with breakfast');

    ($this->save)([followerRow(['plan_ids' => [$plan->id], 'room_type_ids' => [$this->chalet->id]])], [basicRateRow($this->chalet, 'per_guest', '85.00', '95.00'), basicRateRow($this->longhouse, 'per_guest', '60.00', '70.00')])
        ->assertSessionHasErrors('more_rates.0.parent_basis');

    expect(($this->planNamed)('Basic Rate')->pricing_basis)->toBe(PricingBasis::PerRoom);
});

test('a follower that keeps a room type moved to the other basis is refused, and dropping it lets the move through', function () {
    ($this->save)([followerRow(['room_type_ids' => [$this->chalet->id, $this->longhouse->id]])]);
    $plan = ($this->planNamed)('Room with breakfast');

    ($this->save)([followerRow(['plan_ids' => [$plan->id], 'room_type_ids' => [$this->chalet->id, $this->longhouse->id]])], ($this->basic)('per_guest'))
        ->assertSessionHasErrors('more_rates.0.room_type_ids');

    ($this->save)([followerRow(['plan_ids' => [$plan->id], 'room_type_ids' => [$this->chalet->id]])], ($this->basic)('per_guest'))
        ->assertSessionHasNoErrors();

    expect($plan->refresh()->roomTypes()->pluck('room_types.id')->all())->toBe([$this->chalet->id])
        ->and(($this->planNamed)('Basic Rate / Room')->roomTypes()->pluck('room_types.id')->all())->toBe([$this->chalet->id]);
});

test('names cannot be a Basic Rate name, repeat, or belong to another active plan', function () {
    RatePlan::factory()->for($this->property)->create(['name' => 'Corporate']);

    ($this->save)([
        followerRow(['name' => 'basic rate', 'room_type_ids' => [$this->chalet->id]]),
        followerRow(['key' => 'a', 'name' => 'Promo', 'room_type_ids' => [$this->chalet->id]]),
        followerRow(['key' => 'b', 'name' => 'promo', 'room_type_ids' => [$this->chalet->id]]),
        followerRow(['key' => 'c', 'name' => 'Corporate', 'room_type_ids' => [$this->chalet->id]]),
    ])->assertSessionHasErrors(['more_rates.0.name', 'more_rates.2.name', 'more_rates.3.name']);
});

test('a follower needs room types, an amount above 0 and a percentage below 100 when it is a decrease', function () {
    ($this->save)([
        followerRow(['room_type_ids' => []]),
        followerRow(['key' => 'a', 'name' => 'Two', 'amount' => '0', 'room_type_ids' => [$this->chalet->id]]),
        ownRow([], ['key' => 'c', 'name' => 'Four']),
    ])->assertSessionHasErrors(['more_rates.0.room_type_ids', 'more_rates.1.amount', 'more_rates.2.prices']);

    ($this->save)([followerRow(['unit' => 'percent', 'direction' => 'down', 'amount' => '100', 'room_type_ids' => [$this->chalet->id]])])
        ->assertSessionHasErrors('more_rates.0.amount');

    expect(RatePlan::query()->where('property_id', $this->property->id)->count())->toBe(1);
});

test('a saved plan of another property or a Basic Rate plan cannot be a row', function () {
    $other = RatePlan::factory()->for(Property::factory())->create();
    $basic = ($this->planNamed)('Basic Rate');

    ($this->save)([followerRow(['plan_ids' => [$other->id], 'room_type_ids' => [$this->chalet->id]])])->assertSessionHasErrors('more_rates.0.plan_ids.0');
    ($this->save)([followerRow(['plan_ids' => [$basic->id], 'room_type_ids' => [$this->chalet->id]])])->assertSessionHasErrors('more_rates.0.name');
});

test('more rates never count towards the Basic rate check', function () {
    ($this->save)([ownRow([
        ['room_type_id' => $this->chalet->id, 'price_by' => 'per_room', 'weekday' => '150.00', 'weekend' => '170.00'],
        ['room_type_id' => $this->longhouse->id, 'price_by' => 'per_room', 'weekday' => '150.00', 'weekend' => '170.00'],
    ])]);

    Rate::query()->where('rate_plan_id', ($this->planNamed)('Basic Rate')->id)->delete();

    expect((new BasicRateCheck)->state($this->property)->value)->toBe('not_started');
});

test('the preview returns the example of a follower from the first room type on screen', function () {
    $response = $this->actingAs($this->owner)
        ->postJson(route('onboarding.basic-rate.preview', $this->property->code), [
            'rows' => [
                ['room_type_id' => $this->chalet->id, 'price_by' => 'per_room', 'weekday' => '200.00', 'weekend' => ''],
                ['room_type_id' => $this->longhouse->id, 'price_by' => 'per_room', 'weekday' => '100', 'weekend' => ''],
            ],
            'more_rates' => [
                ['key' => 'k1', 'mode' => 'follows', 'parent_basis' => 'per_room', 'direction' => 'down', 'unit' => 'percent', 'amount' => '10', 'room_type_ids' => [$this->longhouse->id]],
                ['key' => 'k2', 'mode' => 'follows', 'parent_basis' => 'per_room', 'direction' => 'up', 'unit' => 'amount', 'amount' => '', 'room_type_ids' => []],
                ['key' => 'k3', 'mode' => 'own'],
            ],
        ])->assertOk();

    expect($response->json('followers'))->toBe([
        ['key' => 'k1', 'parentAmount' => '100.00', 'result' => '90.00'],
        ['key' => 'k2', 'parentAmount' => null, 'result' => null],
    ]);
});
