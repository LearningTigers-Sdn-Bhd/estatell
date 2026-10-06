<?php

use App\Enums\DefaultRoleEnum;
use App\Enums\PolicyScope;
use App\Enums\ReservationStatus;
use App\Enums\SystemTransactionCode;
use App\Enums\UnitStatus;
use App\Models\BusinessDate;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomRevenuePolicy;
use App\Models\RoomType;
use App\Models\TransactionCode;
use App\Models\UnitAddOn;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * A live property with an open business date (today), policies for cancellation (75.00), no-show (60.00) and early
 * departure (40.00 on the unused nights), and a Confirmed unit that arrives today for two nights at 100.00 a night with
 * room 101. The plan has a rate of 130.00 for the night after the stay.
 *
 * @param  array<string, mixed>  $unit
 * @return array{user: User, property: Property, reservation: Reservation, unit: ReservationUnit}
 */
function uaSetup(DefaultRoleEnum $role = DefaultRoleEnum::Owner, array $unit = []): array
{
    $user = User::factory()->withRole($role)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);

    foreach ([
        [SystemTransactionCode::CancellationRevenue, '75.00', PolicyScope::WholeStay],
        [SystemTransactionCode::NoShowRevenue, '60.00', PolicyScope::WholeStay],
        [SystemTransactionCode::EarlyDepartureRevenue, '40.00', PolicyScope::UnusedNights],
    ] as [$code, $amount, $scope]) {
        TransactionCode::factory()->for($property)->system($code)->create();
        RoomRevenuePolicy::factory()->for($property)->fixed($amount)->forCode($code, $scope)->create();
    }

    $roomType = RoomType::factory()->for($property)->create();
    $room = Room::factory()->for($property)->create(['room_type_id' => $roomType->id]);
    $plan = RatePlan::factory()->for($property)->create();
    $plan->roomTypes()->attach($roomType);

    foreach ([[0, '120.00'], [1, '120.00'], [2, '130.00']] as [$offset, $amount]) {
        Rate::factory()->create(['rate_plan_id' => $plan->id, 'room_type_id' => $roomType->id, 'date' => today()->addDays($offset)->toDateString(), 'amount' => $amount]);
    }

    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $unit = ReservationUnit::factory()->for($reservation)->withNights()->create([
        'room_type_id' => $roomType->id,
        'rate_plan_id' => $plan->id,
        'arrival_date' => today()->toDateString(),
        'departure_date' => today()->addDays(2)->toDateString(),
        ...$unit,
    ]);
    $unit->nights()->update(['room_id' => $room->id]);

    return ['user' => $user, 'property' => $property, 'reservation' => $reservation, 'unit' => $unit];
}

/** @param array{property: Property, reservation: Reservation, unit: ReservationUnit} $setup */
function uaUrl(array $setup, string $route): string
{
    return route('reservations.units.'.$route, ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'unit' => $setup['unit']->id]);
}

test('the unit menu lists only what the status and the permissions allow', function (array $unit, DefaultRoleEnum $role, array $expected) {
    $setup = uaSetup($role, $unit);

    $this->actingAs($setup['user'])
        ->get(route('reservations.show', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('unit.actions', fn ($actions) => collect($actions)->pluck('key')->all() === $expected)
            ->where('canWaiveFees', $role === DefaultRoleEnum::Owner));
})->with([
    'confirmed' => [[], DefaultRoleEnum::Owner, ['cancel', 'no-show', 'change-dates']],
    'checked in' => [['status' => UnitStatus::CheckedIn, 'checked_in_at' => now()], DefaultRoleEnum::Owner, ['change-dates']],
    'cancelled' => [['status' => UnitStatus::Cancelled], DefaultRoleEnum::Owner, ['reinstate']],
    'no-show' => [['status' => UnitStatus::NoShow], DefaultRoleEnum::FrontDesk, ['reinstate']],
    'checked out' => [['status' => UnitStatus::CheckedOut, 'checked_in_at' => now()->subDay(), 'checked_out_at' => now()], DefaultRoleEnum::Owner, []],
]);

test('reinstate is not offered once the stay has ended', function () {
    $setup = uaSetup(unit: ['status' => UnitStatus::Cancelled, 'arrival_date' => today()->subDays(3)->toDateString(), 'departure_date' => today()->toDateString()]);

    $this->actingAs($setup['user'])
        ->get(route('reservations.show', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]))
        ->assertInertia(fn (Assert $page) => $page->where('unit.actions', []));
});

test('the fee previews show the policy fee, the free window and the unposted add-ons', function () {
    $setup = uaSetup(unit: ['arrival_date' => today()->addDays(5)->toDateString(), 'departure_date' => today()->addDays(7)->toDateString()]);
    RoomRevenuePolicy::query()->where('code', SystemTransactionCode::CancellationRevenue)->update(['free_until_days' => 2]);
    UnitAddOn::factory()->count(2)->create(['reservation_unit_id' => $setup['unit']->id]);
    UnitAddOn::factory()->posted()->create(['reservation_unit_id' => $setup['unit']->id]);

    $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'cancel-preview'))
        ->assertOk()
        ->assertJson(['fee' => '0.00', 'withinFreeWindow' => true, 'freeUntilDays' => 2, 'daysBeforeArrival' => 5, 'unpostedAddOns' => 2, 'error' => null]);

    $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'no-show-preview'))
        ->assertOk()
        ->assertJson(['fee' => '60.00', 'withinFreeWindow' => false, 'freeUntilDays' => null, 'daysBeforeArrival' => null]);
});

test('a preview with no policy answers with an error and writes nothing', function () {
    $setup = uaSetup();
    RoomRevenuePolicy::query()->delete();

    $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'cancel-preview'))
        ->assertOk()
        ->assertJson(['fee' => null, 'error' => 'The policy for this fee is missing. Check Room revenue in settings.']);

    expect(Activity::query()->count())->toBe(0);
});

test('cancel posts the fee, cancels the unit and toasts', function () {
    $setup = uaSetup();

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'cancel'), ['expected_fee' => '75.00'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::Cancelled)
        ->and($setup['reservation']->fresh()->status)->toBe(ReservationStatus::Cancelled)
        ->and(FolioLine::query()->sole()->amount)->toBe('75.00');
});

test('do not charge is dropped for a user without front-desk.waive-fees', function () {
    $setup = uaSetup(DefaultRoleEnum::FrontDesk);

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'cancel'), ['do_not_charge' => true, 'reason' => 'Guest was ill'])
        ->assertRedirect();

    expect(FolioLine::query()->sole()->amount)->toBe('75.00');
});

test('do not charge from a user who may waive posts no fee and logs the reason', function () {
    $setup = uaSetup();

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'no-show'), ['do_not_charge' => true, 'reason' => 'Flight cancelled', 'remove_add_ons' => true])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $log = Activity::query()->where('event', 'reservation.unit_no_show')->sole();

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::NoShow)
        ->and(FolioLine::query()->count())->toBe(0)
        ->and($log->properties['did_not_charge'])->toBeTrue();
});

test('a stale fee is refused on the expected fee field and nothing changes', function () {
    $setup = uaSetup();

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'cancel'), ['expected_fee' => '50.00'])
        ->assertSessionHasErrors('expected_fee');

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::Confirmed)
        ->and(FolioLine::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
});

test('do not charge without a reason is refused on the reason field', function () {
    $setup = uaSetup();

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'cancel'), ['do_not_charge' => true])
        ->assertSessionHasErrors('reason');

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::Confirmed);
});

test('reinstate needs a reason, previews the new total and moves the unit back to Confirmed', function () {
    $setup = uaSetup(unit: ['status' => UnitStatus::Cancelled]);

    $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'reinstate-preview'))
        ->assertOk()
        ->assertJson(['previousTotal' => '200.00', 'newTotal' => '240.00', 'error' => null]);

    $this->actingAs($setup['user'])->post(uaUrl($setup, 'reinstate'), ['reason' => ''])->assertSessionHasErrors('reason');
    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::Cancelled);

    $this->actingAs($setup['user'])->post(uaUrl($setup, 'reinstate'), ['reason' => 'Guest called back'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::Confirmed);
});

test('the dates preview shows the price difference of a longer and a shorter stay', function () {
    $setup = uaSetup();

    $this->actingAs($setup['user'])
        ->postJson(uaUrl($setup, 'dates-preview'), ['arrival_date' => today()->toDateString(), 'departure_date' => today()->addDays(3)->toDateString()])
        ->assertOk()
        ->assertJson(['previousTotal' => '200.00', 'newTotal' => '330.00', 'difference' => '130.00', 'nights' => 3, 'isEarlyDeparture' => false, 'fee' => null, 'restricted' => false, 'error' => null]);

    $this->actingAs($setup['user'])
        ->postJson(uaUrl($setup, 'dates-preview'), ['arrival_date' => today()->toDateString(), 'departure_date' => today()->addDay()->toDateString()])
        ->assertOk()
        ->assertJson(['newTotal' => '100.00', 'difference' => '-100.00', 'nights' => 1, 'isEarlyDeparture' => true, 'fee' => '40.00']);

    expect(Activity::query()->count())->toBe(0)
        ->and($setup['unit']->fresh()->departure_date->toDateString())->toBe(today()->addDays(2)->toDateString());
});

test('the dates preview refuses a departure that is not after arrival', function () {
    $setup = uaSetup();

    $this->actingAs($setup['user'])
        ->postJson(uaUrl($setup, 'dates-preview'), ['arrival_date' => today()->toDateString(), 'departure_date' => today()->toDateString()])
        ->assertOk()
        ->assertJson(['newTotal' => null, 'error' => 'Departure must be after arrival.']);
});

test('change dates extends the stay and a shorter stay posts the early departure fee', function () {
    $setup = uaSetup();

    $this->actingAs($setup['user'])
        ->put(uaUrl($setup, 'dates'), ['arrival_date' => today()->toDateString(), 'departure_date' => today()->addDays(3)->toDateString()])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($setup['unit']->fresh()->departure_date->toDateString())->toBe(today()->addDays(3)->toDateString());

    $this->actingAs($setup['user'])
        ->put(uaUrl($setup, 'dates'), ['arrival_date' => today()->toDateString(), 'departure_date' => today()->addDay()->toDateString(), 'expected_fee' => '40.00'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($setup['unit']->fresh()->departure_date->toDateString())->toBe(today()->addDay()->toDateString())
        ->and(FolioLine::query()->sole()->amount)->toBe('40.00');
});

test('unchanged dates are refused on the departure field', function () {
    $setup = uaSetup();

    $this->actingAs($setup['user'])
        ->put(uaUrl($setup, 'dates'), ['arrival_date' => today()->toDateString(), 'departure_date' => today()->addDays(2)->toDateString()])
        ->assertSessionHasErrors('departure_date');
});

test('every unit action route needs reservations.manage', function (string $method, string $route) {
    $setup = uaSetup();
    $outsider = User::factory()->create(['property_id' => $setup['property']->id]);

    $this->actingAs($outsider)->{$method}(uaUrl($setup, $route), [])->assertForbidden();
})->with([
    ['post', 'cancel-preview'],
    ['post', 'cancel'],
    ['post', 'no-show-preview'],
    ['post', 'no-show'],
    ['post', 'reinstate-preview'],
    ['post', 'reinstate'],
    ['post', 'dates-preview'],
    ['put', 'dates'],
]);

test('a unit of another reservation is a 404', function () {
    $setup = uaSetup();
    $other = Reservation::factory()->create(['property_id' => $setup['property']->id]);
    $foreign = ReservationUnit::factory()->for($other)->create();

    $this->actingAs($setup['user'])
        ->post(route('reservations.units.cancel', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'unit' => $foreign->id]))
        ->assertNotFound();

    expect($foreign->fresh()->status)->toBe(UnitStatus::Confirmed);
});
