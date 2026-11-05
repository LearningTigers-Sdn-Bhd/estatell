<?php

use App\Enums\AgeBandPricing;
use App\Enums\BookingSourceType;
use App\Enums\ChargingUnit;
use App\Enums\CompanyAccess;
use App\Enums\PaymentPurpose;
use App\Enums\SystemTransactionCode;
use App\Enums\UnitStatus;
use App\Jobs\ReleaseExpiredKioskHolds;
use App\Models\ChargeItem;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Kiosk;
use App\Models\KioskWalkInHold;
use App\Models\Payment;
use App\Models\Property;
use App\Models\PropertyAgeGroup;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\RatePlanChildPrice;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\TransactionCode;
use Database\Factories\BookingSourceFactory;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/KioskSetup.php';

/** @return array<string, mixed> */
function kioskWalkInSetup(): array
{
    Http::preventStrayRequests();
    $setup = kioskStaySetup();
    $property = $setup['property'];
    $property->settings->forceFill(['kiosk_walk_in_enabled' => true, 'security_deposit_amount' => '100.00', 'auto_assign_rooms' => true, 'card_required_for_check_in' => false])->save();
    $type = $setup['unit']->roomType;
    $type->forceFill(['max_adults' => 4, 'max_children' => 2])->save();
    $room = Room::factory()->for($property)->create(['room_type_id' => $type->id, 'number' => '101']);
    $plan = RatePlan::factory()->for($property)->create();
    $type->ratePlans()->attach($plan);
    foreach (['2026-10-14', '2026-10-15'] as $date) {
        Rate::factory()->create(['rate_plan_id' => $plan->id, 'room_type_id' => $type->id, 'date' => $date, 'amount' => '100.00']);
    }
    BookingSourceFactory::systemFor($property->id, BookingSourceType::WalkIn);
    TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $property->id]);

    return [...$setup, 'type' => $type, 'plan' => $plan, 'room' => $room];
}

/** @return array<string, mixed> */
function kioskWalkInBody(array $changes = []): array
{
    return ['arrival' => '2026-10-14', 'nights' => 2, 'adults' => 2, 'children' => 0, 'child_ages' => [], ...$changes];
}

it('quotes the property room using existing rates and creates no booking', function () {
    ['type' => $type] = kioskWalkInSetup();

    kioskPost('/walk-in/quote', kioskWalkInBody())->assertOk()->assertExactJson(['options' => [[
        'room_type_id' => (string) $type->id, 'name' => 'Deluxe Double', 'total' => ['amount_minor' => 20000, 'currency' => 'MYR'], 'available' => true,
    ]]]);
    expect(KioskWalkInHold::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('books an unpaid hold with a server price, a unit folio and an empty guest for the ID step', function () {
    ['type' => $type, 'property' => $property, 'kiosk' => $kiosk] = kioskWalkInSetup();

    $answer = kioskPost('/walk-in/book', kioskWalkInBody(['room_type_id' => $type->id, 'total' => 1, 'property_id' => 999999, 'rate_plan_id' => 999999]))
        ->assertOk()->assertJsonPath('deposit_due', ['amount_minor' => 10000, 'currency' => 'MYR'])
        ->assertJsonPath('hold_expires_at', now()->addMinutes(10)->toRfc3339String());
    $stay = ReservationUnit::query()->findOrFail($answer->json('stay_id'));
    expect($stay->status)->toBe(UnitStatus::Confirmed)->and($stay->property_id)->toBe($property->id)
        ->and($stay->reservation->created_by)->toBeNull()->and($stay->reservation->booker->full_name)->toBe('')
        ->and($stay->nights()->sum('amount'))->toBe('200.00')
        ->and($stay->nights()->whereNull('room_id')->count())->toBe(0)
        ->and(KioskWalkInHold::query()->sole()->kiosk_id)->toBe($kiosk->id)
        ->and(Folio::query()->where('reservation_unit_id', $stay->id)->exists())->toBeTrue();
    kioskPost("/stays/{$stay->id}/identity", ['kind' => 'my_kad', 'number' => '900101141234', 'full_name' => 'AISHA RAHMAN', 'nationality' => 'MY'])->assertOk();
    expect($stay->reservation->booker->refresh()->full_name)->toBe('AISHA RAHMAN');
    Http::assertNothingSent();
});

it('prices and saves each child age with the existing age-band rules', function () {
    ['type' => $type, 'plan' => $plan, 'property' => $property] = kioskWalkInSetup();
    $plan->forceFill(['pricing_basis' => 'per_guest'])->save();
    $young = PropertyAgeGroup::factory()->for($property)->create(['min_age' => 0, 'max_age' => 5]);
    $older = PropertyAgeGroup::factory()->for($property)->create(['min_age' => 6, 'max_age' => 17]);
    RatePlanChildPrice::factory()->create(['rate_plan_id' => $plan->id, 'property_age_group_id' => $young->id, 'pricing' => AgeBandPricing::Free]);
    RatePlanChildPrice::factory()->create(['rate_plan_id' => $plan->id, 'property_age_group_id' => $older->id, 'pricing' => AgeBandPricing::Fixed, 'value' => '40.00']);
    $body = kioskWalkInBody(['children' => 2, 'child_ages' => [3, 15]]);

    kioskPost('/walk-in/quote', $body)->assertOk()->assertJsonPath('options.0.total.amount_minor', 48000);
    $answer = kioskPost('/walk-in/book', [...$body, 'room_type_id' => $type->id])->assertOk();
    $stay = ReservationUnit::query()->findOrFail($answer->json('stay_id'));
    expect($stay->children)->toBe(2)->and($stay->childAges()->orderBy('id')->pluck('age')->all())->toBe([3, 15])
        ->and($stay->nights()->sum('amount'))->toBe('480.00');
});

it('requires valid stay details and an age for every child', function (array $changes) {
    ['type' => $type] = kioskWalkInSetup();

    kioskPost('/walk-in/quote', kioskWalkInBody($changes))->assertStatus(422)->assertJsonPath('error.code', 'no_room_available');
    kioskPost('/walk-in/book', kioskWalkInBody(['room_type_id' => $type->id, ...$changes]))->assertStatus(422);
    expect(KioskWalkInHold::query()->count())->toBe(0);
})->with([
    [['children' => 1, 'child_ages' => []]], [['children' => 2, 'child_ages' => [3]]],
    [['children' => 1, 'child_ages' => [18]]], [['children' => 1, 'child_ages' => [-1]]],
    [['children' => 1, 'child_ages' => ['unknown']]], [['nights' => 0]], [['nights' => 31]],
    [['arrival' => '2026-02-31']], [['adults' => 0]],
]);

it('offers no room and writes nothing when walk-in is disabled', function () {
    ['type' => $type, 'property' => $property] = kioskWalkInSetup();
    $property->settings->forceFill(['kiosk_walk_in_enabled' => false])->save();

    kioskPost('/walk-in/quote', kioskWalkInBody())->assertExactJson(['options' => []]);
    kioskPost('/walk-in/book', kioskWalkInBody(['room_type_id' => $type->id]))->assertStatus(409)->assertJsonPath('error.code', 'no_room_available');
    expect(KioskWalkInHold::query()->count())->toBe(0);
});

it('hides a foreign or missing room type in the same way', function () {
    kioskWalkInSetup();
    $other = RoomType::factory()->create();

    foreach ([$other->id, 999999] as $type) {
        kioskPost('/walk-in/book', kioskWalkInBody(['room_type_id' => $type]))->assertStatus(409)->assertJsonPath('error.code', 'no_room_available');
    }
    expect(KioskWalkInHold::query()->count())->toBe(0);
});

it('does not sell stopped, archived, over-capacity or past stays', function (string $reason) {
    ['type' => $type, 'plan' => $plan] = kioskWalkInSetup();
    $body = kioskWalkInBody();
    match ($reason) {
        'stopped' => Rate::query()->where('rate_plan_id', $plan->id)->update(['stop_sell' => true]),
        'archived' => $plan->forceFill(['archived_at' => now()])->save(),
        'capacity' => $body['adults'] = 5,
        'past' => $body['arrival'] = '2026-10-13',
    };

    kioskPost('/walk-in/quote', $body)->assertExactJson(['options' => []]);
    kioskPost('/walk-in/book', [...$body, 'room_type_id' => $type->id])->assertStatus(409)->assertJsonPath('error.code', 'no_room_available');
})->with(['stopped', 'archived', 'capacity', 'past']);

it('does not sell the last room twice and leaves no extra guest on refusal', function () {
    ['type' => $type] = kioskWalkInSetup();
    kioskPost('/walk-in/book', kioskWalkInBody(['room_type_id' => $type->id]))->assertOk();
    $guests = Guest::query()->count();

    kioskPost('/walk-in/quote', kioskWalkInBody())->assertJsonPath('options.0.available', false);
    kioskPost('/walk-in/book', kioskWalkInBody(['room_type_id' => $type->id]))->assertStatus(409)->assertJsonPath('error.code', 'no_room_available');
    expect(KioskWalkInHold::query()->count())->toBe(1)->and(Guest::query()->count())->toBe($guests);
});

it('replays one hold for the same key without renewing an expired hold', function () {
    ['type' => $type] = kioskWalkInSetup();
    $headers = ['Idempotency-Key' => 'walk-in-1'];
    $first = kioskPost('/walk-in/book', kioskWalkInBody(['room_type_id' => $type->id]), headers: $headers)->assertOk();
    $this->travelTo(now()->addMinutes(11));
    ReleaseExpiredKioskHolds::dispatchSync();

    kioskPost('/walk-in/book', kioskWalkInBody(['room_type_id' => $type->id, 'adults' => 3]), headers: $headers)
        ->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertExactJson($first->json());
    expect(KioskWalkInHold::query()->count())->toBe(1);
});

it('releases an expired unpaid room once and rejects the old reference and by-id check-in', function () {
    ['type' => $type] = kioskWalkInSetup();
    $answer = kioskPost('/walk-in/book', kioskWalkInBody(['room_type_id' => $type->id]))->assertOk();
    $stay = ReservationUnit::query()->findOrFail($answer->json('stay_id'));
    $this->travelTo(now()->addMinutes(11));

    kioskPost('/bookings/find', ['reference' => $answer->json('booking_ref')])->assertStatus(409)->assertJsonPath('error.code', 'hold_expired');
    kioskPost("/stays/{$stay->id}/check-in")->assertStatus(409)->assertJsonPath('error.code', 'hold_expired');
    ReleaseExpiredKioskHolds::dispatchSync();
    ReleaseExpiredKioskHolds::dispatchSync();
    expect($stay->refresh()->status)->toBe(UnitStatus::Cancelled)
        ->and($stay->nights()->whereNotNull('room_id')->count())->toBe(0)
        ->and(KioskWalkInHold::query()->sole()->released_at)->not->toBeNull()
        ->and(Activity::query()->where('event', 'kiosk.hold_expired')->count())->toBe(1);
    kioskPost('/bookings/find', ['reference' => $answer->json('booking_ref')])->assertStatus(409)->assertJsonPath('error.code', 'hold_expired');
    kioskRead("/stays/{$stay->id}/eligibility")->assertStatus(409)->assertJsonPath('error.code', 'hold_expired');
    kioskPost('/walk-in/quote', kioskWalkInBody())->assertJsonPath('options.0.available', true);
});

it('keeps any paid hold after expiry, including a partial deposit on the master folio', function (bool $master) {
    ['type' => $type, 'property' => $property] = kioskWalkInSetup();
    $answer = kioskPost('/walk-in/book', kioskWalkInBody(['room_type_id' => $type->id]))->assertOk();
    $stay = ReservationUnit::query()->findOrFail($answer->json('stay_id'));
    $folio = $master ? Folio::factory()->for($property)->master()->create(['reservation_id' => $stay->reservation_id]) : Folio::query()->where('reservation_unit_id', $stay->id)->sole();
    Payment::factory()->for($folio)->create(['amount' => '25.00', 'purpose' => PaymentPurpose::Advance]);
    $this->travelTo(now()->addMinutes(11));

    ReleaseExpiredKioskHolds::dispatchSync();

    expect($stay->refresh()->status)->toBe(UnitStatus::Confirmed)->and(KioskWalkInHold::query()->sole()->released_at)->toBeNull();
    kioskPost('/bookings/find', ['reference' => $answer->json('booking_ref')])->assertOk();
})->with([false, true]);

it('does not release a stay that checked in before the hold expired', function () {
    ['type' => $type, 'property' => $property] = kioskWalkInSetup();
    $property->settings->forceFill(['security_deposit_amount' => '0.00'])->save();
    $answer = kioskPost('/walk-in/book', kioskWalkInBody(['room_type_id' => $type->id]))->assertOk();
    $stay = ReservationUnit::query()->findOrFail($answer->json('stay_id'));
    kioskPost("/stays/{$stay->id}/check-in")->assertOk();
    $this->travelTo(now()->addMinutes(11));

    ReleaseExpiredKioskHolds::dispatchSync();

    expect($stay->refresh()->status)->toBe(UnitStatus::CheckedIn);
});

it('requires a token and kiosk scope on both walk-in routes', function () {
    ['type' => $type, 'kiosk' => $kiosk] = kioskWalkInSetup();
    foreach ([null, KIOSK_TEST_TOKEN] as $token) {
        if ($token !== null) {
            PersonalAccessToken::query()->update(['abilities' => ['v1:read']]);
        }
        kioskPost('/walk-in/quote', kioskWalkInBody(), token: $token)->assertUnauthorized()->assertJsonPath('error.code', 'kiosk_unauthorized');
        kioskPost('/walk-in/book', kioskWalkInBody(['room_type_id' => $type->id]), token: $token)->assertUnauthorized();
    }
});

it('limits walk-in quotes and bookings by token', function () {
    ['type' => $type] = kioskWalkInSetup();
    foreach (range(1, 20) as $attempt) {
        kioskPost('/walk-in/quote', kioskWalkInBody())->assertOk();
    }

    kioskPost('/walk-in/quote', kioskWalkInBody())->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    kioskPost('/walk-in/book', kioskWalkInBody(['room_type_id' => $type->id]))->assertStatus(429);
});

it('limits the shared IP even when several kiosk tokens are used', function () {
    ['property' => $property] = kioskWalkInSetup();
    foreach (range(1, 3) as $kiosk) {
        $token = 'est_live_walkinkiosk'.$kiosk;
        Kiosk::factory()->for($property)->withToken($token)->create();
        foreach (range(1, $kiosk === 3 ? 10 : 15) as $attempt) {
            kioskPost('/walk-in/quote', kioskWalkInBody(), token: $token)->assertOk();
        }
    }

    kioskPost('/walk-in/quote', kioskWalkInBody(), token: $token)->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
});

it('includes automatic add-ons in the quote and saves them through the booking action', function () {
    ['type' => $type, 'property' => $property] = kioskWalkInSetup();
    ChargeItem::factory()->for($property)->create(['auto_add' => true, 'charging_unit' => ChargingUnit::PerNight, 'default_amount' => '10.00']);

    kioskPost('/walk-in/quote', kioskWalkInBody())->assertOk()->assertJsonPath('options.0.total.amount_minor', 22000);
    $answer = kioskPost('/walk-in/book', kioskWalkInBody(['room_type_id' => $type->id]))->assertOk();
    $stay = ReservationUnit::query()->findOrFail($answer->json('stay_id'));
    expect($stay->addOns()->count())->toBe(2)->and($stay->addOns()->sum('unit_amount'))->toBe('20.00');
});

it('chooses the lowest eligible public rate and excludes company-only plans', function () {
    ['type' => $type, 'property' => $property] = kioskWalkInSetup();
    $private = RatePlan::factory()->for($property)->create(['company_access' => CompanyAccess::All]);
    $public = RatePlan::factory()->for($property)->create();
    $type->ratePlans()->attach([$private->id, $public->id]);
    foreach (['2026-10-14', '2026-10-15'] as $date) {
        Rate::factory()->create(['rate_plan_id' => $private->id, 'room_type_id' => $type->id, 'date' => $date, 'amount' => '1.00']);
        Rate::factory()->create(['rate_plan_id' => $public->id, 'room_type_id' => $type->id, 'date' => $date, 'amount' => '80.00']);
    }

    kioskPost('/walk-in/quote', kioskWalkInBody())->assertJsonPath('options.0.total.amount_minor', 16000);
    $answer = kioskPost('/walk-in/book', kioskWalkInBody(['room_type_id' => $type->id, 'rate_plan_id' => $private->id]))->assertOk();
    expect(ReservationUnit::query()->findOrFail($answer->json('stay_id'))->rate_plan_id)->toBe($public->id);
});

it('returns a safe server error without creating a guest when the walk-in source is missing', function () {
    ['type' => $type, 'property' => $property] = kioskWalkInSetup();
    $source = BookingSourceFactory::systemFor($property->id, BookingSourceType::WalkIn);
    $source->delete();
    $guests = Guest::query()->count();

    kioskPost('/walk-in/book', kioskWalkInBody(['room_type_id' => $type->id]))->assertStatus(409)->assertJsonPath('error.code', 'server_error');
    expect(Guest::query()->count())->toBe($guests)->and(KioskWalkInHold::query()->count())->toBe(0);
});

it('does not reveal another property expired hold through by-id calls', function () {
    ['type' => $type] = kioskWalkInSetup();
    $answer = kioskPost('/walk-in/book', kioskWalkInBody(['room_type_id' => $type->id]))->assertOk();
    Kiosk::factory()->for(Property::factory()->create())->withToken('est_live_otherproperty')->create();
    $this->travelTo(now()->addMinutes(11));

    kioskRead('/stays/'.$answer->json('stay_id').'/eligibility', token: 'est_live_otherproperty')->assertNotFound()->assertJsonPath('error.code', 'booking_not_found');
});

it('still limits unauthenticated API requests by IP before reaching the controller', function () {
    kioskWalkInSetup();
    foreach (range(1, 120) as $attempt) {
        kioskPost('/walk-in/quote', kioskWalkInBody(), token: null)->assertUnauthorized();
    }

    kioskPost('/walk-in/quote', kioskWalkInBody(), token: null)->assertStatus(429)->assertJsonPath('error.code', 'rate_limited')->assertHeader('Retry-After');
    kioskPost('/walk-in/quote', kioskWalkInBody())->assertOk();
});
