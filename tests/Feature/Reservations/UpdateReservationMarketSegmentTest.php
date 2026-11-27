<?php

use App\Actions\Reservations\ReservationResult;
use App\Actions\Reservations\UpdateReservationMarketSegment;
use App\Enums\DefaultRoleEnum;
use App\Enums\ReservationStatus;
use App\Models\MarketSegment;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * @return array{user: User, property: Property, reservation: Reservation, segment: MarketSegment}
 */
function marketSegmentSetup(DefaultRoleEnum $role = DefaultRoleEnum::FrontDesk): array
{
    $user = User::factory()->withRole($role)->create();
    PropertySetting::factory()->for($user->property)->create();
    $reservation = Reservation::factory()->create(['property_id' => $user->property_id]);
    ReservationUnit::factory()->for($reservation)->create();
    $segment = MarketSegment::factory()->for($user->property)->create(['name' => 'Leisure']);

    return ['user' => $user, 'property' => $user->property, 'reservation' => $reservation, 'segment' => $segment];
}

function reservationMarketSegmentUrl(Property $property, Reservation $reservation): string
{
    return route('reservations.market-segment.update', ['property' => $property->code, 'reservation' => $reservation->id]);
}

test('it sets the segment on a closed reservation too and the activity names it', function () {
    $setup = marketSegmentSetup();
    $setup['reservation']->forceFill(['status' => ReservationStatus::Completed])->save();

    $result = app(UpdateReservationMarketSegment::class)($setup['user'], $setup['reservation'], $setup['segment']->id);

    expect($result->isRefused())->toBeFalse()
        ->and($setup['reservation']->fresh()->market_segment_id)->toBe($setup['segment']->id);

    $this->actingAs($setup['user'])
        ->get(route('reservations.activity', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]))
        ->assertInertia(fn (Assert $page) => $page->where('activity.data', fn ($entries) => collect($entries)->pluck('text')->contains('Market segment: None → Leisure')));
});

test('the same segment is not a change, so one turned off stays and nothing is logged', function () {
    $setup = marketSegmentSetup();
    $setup['segment']->forceFill(['active' => false])->save();
    $setup['reservation']->forceFill(['market_segment_id' => $setup['segment']->id])->save();

    app(UpdateReservationMarketSegment::class)($setup['user'], $setup['reservation'], $setup['segment']->id);

    expect($setup['reservation']->fresh()->market_segment_id)->toBe($setup['segment']->id)
        ->and(Activity::query()->where('event', 'reservation.market_segment_updated')->exists())->toBeFalse();
});

test('it refuses a segment that is off or belongs to another property', function (string $kind) {
    $setup = marketSegmentSetup();
    $segment = $kind === 'off'
        ? MarketSegment::factory()->for($setup['property'])->inactive()->create(['name' => 'Crew'])
        : MarketSegment::factory()->create();

    $result = app(UpdateReservationMarketSegment::class)($setup['user'], $setup['reservation'], $segment->id);

    expect($result->refusal)->toBe(ReservationResult::REFUSAL_MARKET_SEGMENT_NOT_AVAILABLE)
        ->and($setup['reservation']->fresh()->market_segment_id)->toBeNull();
})->with(['turned off' => 'off', 'another property' => 'other']);

test('staff change the segment, a refusal is a field error, and Finance gets 403', function () {
    $setup = marketSegmentSetup();
    $off = MarketSegment::factory()->for($setup['property'])->inactive()->create(['name' => 'Crew']);
    $url = reservationMarketSegmentUrl($setup['property'], $setup['reservation']);

    $this->actingAs($setup['user'])->put($url, ['market_segment_id' => $off->id])
        ->assertSessionHasErrors(['market_segment_id' => 'This market segment is not available. Pick an active segment.']);

    $this->put($url, ['market_segment_id' => $setup['segment']->id])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Market segment saved.');

    expect($setup['reservation']->fresh()->market_segment_id)->toBe($setup['segment']->id);

    $finance = User::factory()->withRole(DefaultRoleEnum::Finance)->create(['property_id' => $setup['property']->id]);

    $this->actingAs($finance)->put($url, ['market_segment_id' => null])->assertForbidden();
});
