<?php

use App\Actions\Rates\ArchiveRatePlan;
use App\Actions\Rates\CreateDerivedRatePlan;
use App\Actions\Rates\ReconcileRoomTypeRatePlans;
use App\Actions\Rates\SaveRatePlanDerivation;
use App\Enums\PricingBasis;
use App\Enums\RateDerivation;
use App\Enums\SellUnit;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Rates\RateQuote;
use App\Services\Rates\RateQuoteRequest;
use App\Services\Rates\RateQuoteService;
use App\Services\Reservations\ReservationFormOptions;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** @return array{0: User, 1: Property} */
function dayUseOwner(): array
{
    $owner = User::factory()->owner()->create();
    PropertySetting::factory()->for($owner->property)->create();

    return [$owner, $owner->property];
}

test('owners create a day use plan with hours sold and land on its room types tab', function () {
    [$owner, $property] = dayUseOwner();

    $this->actingAs($owner)
        ->post(route('settings.rate-plans.store', $property->code), [
            'name' => 'Day use', 'pricing_basis' => 'day_use', 'day_use_min_hours' => 2, 'day_use_max_hours' => 6,
        ])
        ->assertRedirect();

    $plan = RatePlan::query()->where('name', 'Day use')->sole();

    expect($plan->pricing_basis)->toBe(PricingBasis::DayUse)
        ->and($plan->day_use_min_hours)->toBe(2)
        ->and($plan->day_use_max_hours)->toBe(6)
        ->and($plan->day_use_from)->toBeNull();
});

test('a day use plan cannot sell a bed room type', function () {
    [$owner, $property] = dayUseOwner();
    $dorm = RoomType::factory()->for($property)->create(['sell_unit' => 'bed']);

    $this->actingAs($owner)
        ->post(route('settings.rate-plans.store', $property->code), [
            'name' => 'Day use', 'pricing_basis' => 'day_use', 'day_use_min_hours' => 2, 'day_use_max_hours' => 6, 'room_type_ids' => [$dorm->id],
        ])
        ->assertSessionHasErrors('room_type_ids');

    expect(RatePlan::query()->where('name', 'Day use')->exists())->toBeFalse();
});

test('day use hours are validated on creation and on the details tab', function (array $payload, string $field) {
    [$owner, $property] = dayUseOwner();
    $plan = RatePlan::factory()->for($property)->dayUse(2, 6)->create(['name' => 'Day use']);

    if (str_starts_with($field, 'day_use_m')) {
        $this->actingAs($owner)
            ->post(route('settings.rate-plans.store', $property->code), ['name' => 'Another', 'pricing_basis' => 'day_use', ...$payload])
            ->assertSessionHasErrors($field);
    }

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.update', [$property->code, $plan]), ['name' => 'Day use', 'day_use_min_hours' => 2, 'day_use_max_hours' => 6, ...$payload])
        ->assertSessionHasErrors($field);
})->with([
    'min below 1' => [['day_use_min_hours' => 0, 'day_use_max_hours' => 4], 'day_use_min_hours'],
    'max above 24' => [['day_use_min_hours' => 1, 'day_use_max_hours' => 25], 'day_use_max_hours'],
    'max below min' => [['day_use_min_hours' => 5, 'day_use_max_hours' => 3], 'day_use_max_hours'],
    'only one window time' => [['day_use_min_hours' => 2, 'day_use_max_hours' => 4, 'day_use_from' => '08:00'], 'day_use_until'],
    'window ends before it starts' => [['day_use_min_hours' => 2, 'day_use_max_hours' => 4, 'day_use_from' => '18:00', 'day_use_until' => '08:00'], 'day_use_until'],
    'window shorter than the minimum hours' => [['day_use_min_hours' => 4, 'day_use_max_hours' => 6, 'day_use_from' => '08:00', 'day_use_until' => '11:00'], 'day_use_until'],
]);

test('details save hours sold and the sold between window', function () {
    [$owner, $property] = dayUseOwner();
    $plan = RatePlan::factory()->for($property)->dayUse(2, 6)->create(['name' => 'Day use']);

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.update', [$property->code, $plan]), [
            'name' => 'Day use', 'day_use_min_hours' => 3, 'day_use_max_hours' => 8, 'day_use_from' => '08:00', 'day_use_until' => '18:00',
        ])
        ->assertRedirect();

    $plan->refresh();

    expect($plan->day_use_min_hours)->toBe(3)
        ->and($plan->day_use_max_hours)->toBe(8)
        ->and(substr($plan->day_use_from, 0, 5))->toBe('08:00')
        ->and(substr($plan->day_use_until, 0, 5))->toBe('18:00');

    $this->actingAs($owner)->get(route('settings.rate-plans.edit', [$property->code, $plan]))
        ->assertInertia(fn ($page) => $page->where('ratePlan.dayUseFrom', '08:00')->where('ratePlan.dayUseUntil', '18:00'));
});

test('a day use plan has no children or discounts tab and cannot follow or be followed', function () {
    [$owner, $property] = dayUseOwner();
    $plan = RatePlan::factory()->for($property)->dayUse()->create();
    $other = RatePlan::factory()->for($property)->dayUse()->create();
    $basic = RatePlan::factory()->for($property)->create();

    $this->actingAs($owner)->get(route('settings.rate-plans.discounts.edit', [$property->code, $plan]))->assertNotFound();
    $this->actingAs($owner)->get(route('settings.rate-plans.child-prices.edit', [$property->code, $plan]))->assertNotFound();

    expect(app(SaveRatePlanDerivation::class)($owner, $plan, $other->id, RateDerivation::Percent, '10'))->toBeFalse()
        ->and(app(CreateDerivedRatePlan::class)($owner, $plan, 'Child', RateDerivation::Percent, '10', []))->toBeNull()
        ->and(app(SaveRatePlanDerivation::class)($owner, $basic, $plan->id, RateDerivation::Percent, '10'))->toBeFalse();
});

test('a day use plan never counts as the overnight plan of a room type', function () {
    [$owner, $property] = dayUseOwner();
    $roomType = RoomType::factory()->for($property)->create();
    $basic = RatePlan::factory()->for($property)->create();
    $dayUse = RatePlan::factory()->for($property)->dayUse()->create();
    $roomType->ratePlans()->attach([$basic->id, $dayUse->id]);

    expect(app(ArchiveRatePlan::class)($owner, $basic))->toBeFalse()
        ->and(app(ArchiveRatePlan::class)($owner, $dayUse))->toBeTrue();
});

test('reconcile skips day use plans', function () {
    [, $property] = dayUseOwner();
    RatePlan::factory()->for($property)->dayUse()->create(['position' => 1]);
    $roomType = RoomType::factory()->for($property)->create();

    app(ReconcileRoomTypeRatePlans::class)($roomType);

    expect($roomType->ratePlans()->count())->toBe(0);
});

test('the enum throws for a nightly strategy and refuses channels with a reason', function () {
    expect(fn () => PricingBasis::DayUse->strategy())->toThrow(LogicException::class)
        ->and(PricingBasis::DayUse->channelRefusal())->toBe('Day use plans are sold at the front desk only.')
        ->and(PricingBasis::PerRoom->channelRefusal())->toBeNull()
        ->and(PricingBasis::Package->channelRefusal())->toBeNull()
        ->and(PricingBasis::DayUse->fits(SellUnit::Room))->toBeTrue()
        ->and(PricingBasis::DayUse->fits(SellUnit::Bed))->toBeFalse();
});

test('the nightly quote refuses a day use plan and the add unit options flag it', function () {
    [, $property] = dayUseOwner();
    $roomType = RoomType::factory()->for($property)->create();
    $dayUse = RatePlan::factory()->for($property)->dayUse()->create();
    $basic = RatePlan::factory()->for($property)->create();
    $dayUse->roomTypes()->attach($roomType);

    $quote = app(RateQuoteService::class)->quote(new RateQuoteRequest($dayUse, $roomType, CarbonImmutable::parse('2026-10-12'), CarbonImmutable::parse('2026-10-13'), 2, [], 1));

    expect($quote->isRefused())->toBeTrue()
        ->and($quote->refusal)->toBe(RateQuote::REFUSAL_PLAN_NOT_NIGHTLY)
        ->and(collect(app(ReservationFormOptions::class)->addUnit($property)->ratePlans)->mapWithKeys(fn ($option) => [$option->id => $option->isDayUse])->all())->toBe([$dayUse->id => true, $basic->id => false]);
});

test('database checks keep day use hours on day use plans only', function () {
    $property = Property::factory()->create();
    $dayUse = RatePlan::factory()->for($property)->dayUse()->create();

    expect(fn () => RatePlan::factory()->for($property)->create(['day_use_min_hours' => 2]))->toThrow(QueryException::class);
    expect(fn () => DB::table('rate_plans')->where('id', $dayUse->id)->update(['day_use_min_hours' => null]))->toThrow(QueryException::class);
    expect(fn () => DB::table('rate_plans')->where('id', $dayUse->id)->update(['day_use_max_hours' => 25]))->toThrow(QueryException::class);
    expect(fn () => DB::table('rate_plans')->where('id', $dayUse->id)->update(['day_use_from' => '08:00']))->toThrow(QueryException::class);
    expect(fn () => DB::table('rate_plans')->where('id', $dayUse->id)->update(['day_use_from' => '18:00', 'day_use_until' => '08:00']))->toThrow(QueryException::class);
});
