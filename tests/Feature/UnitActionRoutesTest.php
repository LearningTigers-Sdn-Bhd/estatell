<?php

use App\Actions\Payments\RecordPayment;
use App\Data\Payments\NewPaymentData;
use App\Enums\DefaultRoleEnum;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Enums\PolicyScope;
use App\Enums\ReservationStatus;
use App\Enums\SystemTransactionCode;
use App\Enums\UnitStatus;
use App\Models\BusinessDate;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomRevenuePolicy;
use App\Models\RoomType;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\UnitAddOn;
use App\Models\UnitGuest;
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

/**
 * A user of the property whose role holds exactly these permissions.
 *
 * @param  list<string>  $permissions
 */
function uaStaffWith(Property $property, array $permissions): User
{
    $user = User::factory()->for($property)->create();
    $role = Role::query()->create(['property_id' => $property->id, 'name' => 'Only '.implode(',', $permissions)]);
    $role->givePermissionTo($permissions);
    setPermissionsTeamId($property->id);
    $user->assignRole($role);

    return $user;
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
    'confirmed' => [[], DefaultRoleEnum::Owner, ['check-in', 'change-dates', 'cancel', 'no-show']],
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

/**
 * Settings for check-in: no card needed, a 100.00 deposit, a system Room revenue code with an 8% tax flagged Collect at
 * check-in, a Cash method and the system Refund code. Two nights at 100.00 give 16.00 of tax to collect.
 *
 * @param  array{property: Property}  $setup
 */
function uaCheckInSetup(array $setup, array $settings = []): PaymentMethod
{
    $property = $setup['property'];
    $property->settings->update(['card_required_for_check_in' => false, 'security_deposit_amount' => '100.00', ...$settings]);

    $room = TransactionCode::factory()->for($property)->system(SystemTransactionCode::RoomRevenue)->create();
    TransactionCode::factory()->for($property)->system(SystemTransactionCode::Refund)->create();
    $tax = Tax::factory()->for($property)->create(['rate' => '8.00', 'is_inclusive' => false, 'collect_at_check_in' => true, 'foreign_guests_only' => false]);
    $tax->applicableTransactionCodes()->attach($room);

    return PaymentMethod::factory()->create(['property_id' => $property->id, 'type' => PaymentMethodType::Cash, 'requires_reference' => false, 'position' => 1]);
}

test('the check-in preview shows the room, the card status and what Collect now asks', function () {
    $setup = uaSetup();
    uaCheckInSetup($setup);

    $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'check-in-preview'))
        ->assertOk()
        ->assertJson([
            'cardRequired' => false,
            'cardsMissing' => 0,
            'needsNationality' => false,
            'creditExceeded' => false,
            'collection' => ['taxesTotal' => '16.00', 'taxesHidden' => false, 'deposit' => '100.00'],
            'error' => null,
        ])
        ->assertJsonPath('room', $setup['unit']->nights()->first()->room->number)
        ->assertJsonCount(1, 'collection.taxes');

    expect(Activity::query()->count())->toBe(0);
});

test('the check-in preview leaves Collect now out for a user without payment.record', function () {
    $setup = uaSetup();
    $setup['property']->settings->update(['security_deposit_amount' => '100.00']);
    $reader = uaStaffWith($setup['property'], ['reservations.view', 'front-desk.check-in']);

    $this->actingAs($reader)->postJson(uaUrl($setup, 'check-in-preview'))
        ->assertOk()
        ->assertJsonPath('collection', null);
});

test('check in without Collect now checks the unit in', function () {
    $setup = uaSetup();
    uaCheckInSetup($setup);

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'check-in'), [])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn)
        ->and(Payment::query()->count())->toBe(0);
});

test('check in with Collect now takes the taxes as an advance and the deposit on the unit folio', function () {
    $setup = uaSetup();
    $cash = uaCheckInSetup($setup);

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'check-in'), ['collect_now' => true, 'collect_taxes' => true, 'collect_deposit' => true, 'payment_method_id' => $cash->id, 'idempotency_key' => fake()->uuid()])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $payments = Payment::query()->orderBy('id')->get();

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn)
        ->and($payments->pluck('amount')->all())->toBe(['16.00', '100.00'])
        ->and($payments->pluck('purpose')->all())->toBe([PaymentPurpose::Advance, PaymentPurpose::SecurityDeposit])
        ->and(Folio::query()->where('reservation_unit_id', $setup['unit']->id)->count())->toBe(1);
});

test('a refused payment leaves the unit checked in and shows on the method field', function () {
    $setup = uaSetup();
    uaCheckInSetup($setup);

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'check-in'), ['collect_now' => true, 'collect_taxes' => true, 'collect_deposit' => true, 'payment_method_id' => 999999, 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasErrors('payment_method_id');

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn)
        ->and(Payment::query()->count())->toBe(0);
});

test('the amounts of Collect now come from the server, not from the form', function () {
    $setup = uaSetup();
    $cash = uaCheckInSetup($setup);

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'check-in'), ['collect_now' => true, 'collect_taxes' => true, 'collect_deposit' => false, 'payment_method_id' => $cash->id, 'idempotency_key' => fake()->uuid(), 'amount' => '1.00', 'taxes_total' => '1.00'])
        ->assertRedirect();

    expect(Payment::query()->sole()->amount)->toBe('16.00');
});

test('a missing card refuses check-in unless the user may check in without one', function () {
    $setup = uaSetup(DefaultRoleEnum::FrontDesk);
    uaCheckInSetup($setup, ['card_required_for_check_in' => true]);
    UnitGuest::factory()->create(['reservation_unit_id' => $setup['unit']->id, 'is_primary' => true]);

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'check-in'), ['without_card' => true])
        ->assertSessionHasErrors('form');

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::Confirmed);

    $owner = uaSetup(DefaultRoleEnum::Owner);
    uaCheckInSetup($owner, ['card_required_for_check_in' => true]);
    UnitGuest::factory()->create(['reservation_unit_id' => $owner['unit']->id, 'is_primary' => true]);

    $this->actingAs($owner['user'])
        ->post(uaUrl($owner, 'check-in'), ['without_card' => true])
        ->assertSessionHasNoErrors();

    expect($owner['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn);
});

test('a missing nationality is saved to the guest at check-in and a refusal saves nothing', function () {
    $setup = uaSetup();
    uaCheckInSetup($setup);
    Tax::factory()->for($setup['property'])->create(['foreign_guests_only' => true, 'collect_at_check_in' => false]);
    $booker = Guest::query()->findOrFail($setup['reservation']->booker_guest_id);
    $booker->update(['nationality_code' => null]);

    $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'check-in-preview'))
        ->assertJsonPath('needsNationality', true);

    $this->actingAs($setup['user'])->post(uaUrl($setup, 'check-in'), [])->assertSessionHasErrors('nationality_code');

    $setup['unit']->nights()->update(['room_id' => null]);
    $this->actingAs($setup['user'])->post(uaUrl($setup, 'check-in'), ['nationality_code' => 'SG'])->assertSessionHasErrors('form');
    expect($booker->fresh()->nationality_code)->toBeNull();

    $setup['unit']->nights()->update(['room_id' => Room::query()->first()->id]);
    $this->actingAs($setup['user'])->post(uaUrl($setup, 'check-in'), ['nationality_code' => 'SG'])->assertSessionHasNoErrors();

    expect($booker->fresh()->nationality_code)->toBe('SG')
        ->and($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn);
});

/**
 * A checked-in unit that departs today, with a 100.00 security deposit held on its folio.
 *
 * @return array{user: User, property: Property, reservation: Reservation, unit: ReservationUnit}
 */
function uaDepartingSetup(DefaultRoleEnum $role = DefaultRoleEnum::Owner, bool $withDeposit = true): array
{
    $setup = uaSetup($role, ['status' => UnitStatus::CheckedIn, 'checked_in_at' => now()->subDay(), 'arrival_date' => today()->subDay()->toDateString(), 'departure_date' => today()->toDateString()]);
    $cash = uaCheckInSetup($setup);

    if ($withDeposit) {
        app(RecordPayment::class)($setup['user'], $setup['reservation'], null, $setup['unit'], new NewPaymentData($cash->id, PaymentPurpose::SecurityDeposit, '100.00', null, fake()->uuid()));
    }

    return $setup;
}

test('the check-out preview shows the deposit held and the refund that settles the folio', function () {
    $setup = uaDepartingSetup();

    $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'check-out-preview'))
        ->assertOk()
        ->assertJson(['balance' => '-100.00', 'owing' => '0.00', 'depositHeld' => '100.00', 'refundDefault' => '100.00', 'canRefund' => true, 'error' => null]);
});

test('check out refunds the deposit first and then checks the unit out', function () {
    $setup = uaDepartingSetup();

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'check-out'), ['refund_amount' => '100.00', 'idempotency_key' => fake()->uuid()])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedOut)
        ->and(Payment::query()->whereNotNull('refund_of_id')->sole()->amount)->toBe('100.00');
});

test('check out is refused while the deposit credit is still on the folio', function () {
    $setup = uaDepartingSetup();

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'check-out'), [])
        ->assertSessionHasErrors('form');

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn);
});

test('a refund above the deposit held is refused before anything is written', function () {
    $setup = uaDepartingSetup();

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'check-out'), ['refund_amount' => '150.00', 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasErrors('refund_amount');

    expect(Payment::query()->whereNotNull('refund_of_id')->count())->toBe(0)
        ->and($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn);
});

test('a deposit refund from a user without payment.refund is dropped', function () {
    $setup = uaDepartingSetup(DefaultRoleEnum::FrontDesk);

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'check-out'), ['refund_amount' => '100.00', 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasErrors('form');

    expect(Payment::query()->whereNotNull('refund_of_id')->count())->toBe(0);
});

test('a unit with no deposit checks out when the folio is settled', function () {
    $setup = uaDepartingSetup(withDeposit: false);

    $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'check-out-preview'))
        ->assertJson(['depositHeld' => '0.00', 'refundDefault' => '0.00']);

    $this->actingAs($setup['user'])->post(uaUrl($setup, 'check-out'), [])->assertSessionHasNoErrors();

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedOut);
});

test('late check-out previews the fee and posts it with a later time', function () {
    $setup = uaDepartingSetup();
    TransactionCode::factory()->for($setup['property'])->system(SystemTransactionCode::LateCheckoutRevenue)->create();
    RoomRevenuePolicy::factory()->for($setup['property'])->fixed('30.00')->forCode(SystemTransactionCode::LateCheckoutRevenue, PolicyScope::OneCharge)->create();

    $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'late-check-out-preview'))
        ->assertOk()
        ->assertJson(['fee' => '30.00', 'departureTime' => '11:00', 'error' => null])
        ->assertJsonPath('standardTime', substr($setup['property']->settings->check_out_time, 0, 5));

    $this->actingAs($setup['user'])->post(uaUrl($setup, 'late-check-out'), ['new_time' => '09:00'])->assertSessionHasErrors('new_time');

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'late-check-out'), ['new_time' => '16:00', 'expected_fee' => '30.00'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(substr($setup['unit']->fresh()->departure_time, 0, 5))->toBe('16:00')
        ->and(FolioLine::query()->where('amount', '30.00')->count())->toBe(1);
});

test('the menu offers check-out, late check-out and no check-in on the departure date', function () {
    $setup = uaDepartingSetup();

    $this->actingAs($setup['user'])
        ->get(route('reservations.show', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]))
        ->assertInertia(fn (Assert $page) => $page->where('unit.actions', fn ($actions) => collect($actions)->pluck('key')->all() === ['check-out', 'late-check-out', 'change-dates']));
});

test('check in is not offered before the arrival date or on the departure date', function () {
    $setup = uaSetup(unit: ['arrival_date' => today()->addDay()->toDateString(), 'departure_date' => today()->addDays(3)->toDateString()]);

    $this->actingAs($setup['user'])
        ->get(route('reservations.show', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]))
        ->assertInertia(fn (Assert $page) => $page->where('unit.actions', fn ($actions) => collect($actions)->pluck('key')->all() === ['change-dates', 'cancel', 'no-show']));
});

test('the front desk routes need their own permission', function (string $route) {
    $setup = uaSetup();
    $outsider = uaStaffWith($setup['property'], ['reservations.view']);

    $this->actingAs($outsider)->post(uaUrl($setup, $route), [])->assertForbidden();
})->with(['check-in-preview', 'check-in', 'check-out-preview', 'check-out', 'late-check-out-preview', 'late-check-out']);
