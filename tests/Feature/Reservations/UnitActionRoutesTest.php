<?php

use App\Actions\Folio\CreateFolio;
use App\Actions\FrontDesk\CheckInUnit;
use App\Actions\FrontDesk\UndoCheckIn;
use App\Actions\FrontDesk\UndoCheckOut;
use App\Actions\FrontDesk\UnitStayResult;
use App\Actions\Payments\RecordPayment;
use App\Data\Payments\NewPaymentData;
use App\Enums\BillingMode;
use App\Enums\BusinessDateStatus;
use App\Enums\DefaultRoleEnum;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Enums\PermissionEnum;
use App\Enums\PolicyScope;
use App\Enums\ReservationStatus;
use App\Enums\SystemTransactionCode;
use App\Enums\TaxRateType;
use App\Enums\TransactionCategory;
use App\Enums\UnitStatus;
use App\Models\ArInvoice;
use App\Models\BusinessDate;
use App\Models\ChargeItem;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\FolioRoutingRule;
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
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

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
    'confirmed' => [[], DefaultRoleEnum::Owner, ['check-in', 'change-dates', 'cancel', 'no-show', 'move-room']],
    'checked in' => [['status' => UnitStatus::CheckedIn, 'checked_in_at' => now()], DefaultRoleEnum::Owner, ['change-dates', 'move-room', 'undo-check-in']],
    'cancelled' => [['status' => UnitStatus::Cancelled], DefaultRoleEnum::Owner, ['reinstate']],
    'no-show' => [['status' => UnitStatus::NoShow], DefaultRoleEnum::FrontDesk, ['reinstate']],
    'checked out' => [['status' => UnitStatus::CheckedOut, 'checked_in_at' => now()->subDay(), 'checked_out_at' => now()], DefaultRoleEnum::Owner, ['undo-check-out']],
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
        ->assertJson(['newTotal' => '100.00', 'difference' => '-100.00', 'nights' => 1, 'isEarlyDeparture' => false, 'fee' => null]);

    expect(Activity::query()->count())->toBe(0)
        ->and($setup['unit']->fresh()->departure_date->toDateString())->toBe(today()->addDays(2)->toDateString());
});

test('the dates preview shows the early departure fee of a checked-in unit that leaves early', function () {
    $setup = uaSetup(DefaultRoleEnum::Owner, ['status' => UnitStatus::CheckedIn, 'checked_in_at' => now()]);

    $this->actingAs($setup['user'])
        ->postJson(uaUrl($setup, 'dates-preview'), ['arrival_date' => today()->toDateString(), 'departure_date' => today()->addDay()->toDateString()])
        ->assertOk()
        ->assertJson(['newTotal' => '100.00', 'difference' => '-100.00', 'nights' => 1, 'isEarlyDeparture' => true, 'fee' => '40.00']);
});

test('the dates preview treats a shorter stay that also moves the arrival as a new booking, with no fee', function () {
    $setup = uaSetup();
    $setup['unit']->update(['arrival_date' => today()->addDay()->toDateString(), 'departure_date' => today()->addDays(3)->toDateString()]);
    $setup['unit']->nights()->orderByDesc('date')->first()->update(['date' => today()->addDays(2)->toDateString()]);
    $setup['unit']->nights()->orderBy('date')->first()->update(['date' => today()->addDay()->toDateString()]);

    $this->actingAs($setup['user'])
        ->postJson(uaUrl($setup, 'dates-preview'), ['arrival_date' => today()->toDateString(), 'departure_date' => today()->addDay()->toDateString()])
        ->assertOk()
        ->assertJson(['isEarlyDeparture' => false, 'fee' => null, 'error' => null]);
});

test('the dates preview refuses a departure that is not after arrival', function () {
    $setup = uaSetup();

    $this->actingAs($setup['user'])
        ->postJson(uaUrl($setup, 'dates-preview'), ['arrival_date' => today()->toDateString(), 'departure_date' => today()->toDateString()])
        ->assertOk()
        ->assertJson(['newTotal' => null, 'error' => 'Departure must be after arrival.']);
});

test('change dates extends a confirmed stay, and a shorter confirmed stay saves with no fee', function () {
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
        ->and(FolioLine::query()->count())->toBe(0);
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
    $reader = User::factory()->for($setup['property'])->withPermissions(['reservations.view', 'front-desk.check-in'])->create();

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

test('check in with Collect now on a unit whose rooms go to the Master folio takes no tax advance and the deposit still posts', function () {
    $setup = uaSetup();
    $cash = uaCheckInSetup($setup);
    $master = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);
    FolioRoutingRule::factory()->forCategory(TransactionCategory::Room)->toFolio($master)->create(['reservation_id' => $setup['reservation']->id]);

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'check-in'), ['collect_now' => true, 'collect_taxes' => true, 'collect_deposit' => true, 'payment_method_id' => $cash->id, 'idempotency_key' => fake()->uuid()])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn)
        ->and(Payment::query()->pluck('purpose')->all())->toBe([PaymentPurpose::SecurityDeposit])
        ->and(Payment::query()->sole()->folio->type)->toBe(FolioType::Unit);
});

test('a refused payment saves nothing, not even the check-in, and shows on the method field', function () {
    $setup = uaSetup();
    uaCheckInSetup($setup);

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'check-in'), ['collect_now' => true, 'collect_taxes' => true, 'collect_deposit' => true, 'payment_method_id' => 999999, 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasErrors('payment_method_id');

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::Confirmed)
        ->and(Payment::query()->count())->toBe(0);
});

test('after a refused payment the same form can be sent again and checks in with both payments', function () {
    $setup = uaSetup();
    uaCheckInSetup($setup);
    $card = PaymentMethod::factory()->create(['property_id' => $setup['property']->id, 'requires_reference' => true, 'position' => 2]);
    $form = ['collect_now' => true, 'collect_taxes' => true, 'collect_deposit' => true, 'payment_method_id' => $card->id, 'idempotency_key' => fake()->uuid()];

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'check-in'), $form)
        ->assertSessionHasErrors('reference');

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::Confirmed)
        ->and(Payment::query()->count())->toBe(0);

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'check-in'), [...$form, 'reference' => 'TXN-1'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn)
        ->and(Payment::query()->orderBy('id')->pluck('amount')->all())->toBe(['16.00', '100.00']);
});

test('the amounts of Collect now come from the server, not from the form', function () {
    $setup = uaSetup();
    $cash = uaCheckInSetup($setup);

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'check-in'), ['collect_now' => true, 'collect_taxes' => true, 'collect_deposit' => false, 'payment_method_id' => $cash->id, 'idempotency_key' => fake()->uuid(), 'amount' => '1.00', 'taxes_total' => '1.00'])
        ->assertRedirect();

    expect(Payment::query()->sole()->amount)->toBe('16.00');
});

test('the check-in preview offers the full bill, and a unit with no tax or deposit still offers Collect now', function () {
    $setup = uaSetup();
    uaCheckInSetup($setup, ['security_deposit_amount' => '0.00']);
    Tax::query()->where('property_id', $setup['property']->id)->update(['enabled' => false]);

    $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'check-in-preview'))
        ->assertOk()
        ->assertJsonPath('collection.billTotal', '200.00')
        ->assertJsonPath('collection.billHidden', false)
        ->assertJsonPath('collection.taxes', [])
        ->assertJsonPath('collection.deposit', null);
});

test('check in with Room charges and tax records the full bill as one advance in place of the taxes, then the deposit', function () {
    $setup = uaSetup();
    $cash = uaCheckInSetup($setup);
    $bill = $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'check-in-preview'))->json('collection.billTotal');
    $key = fake()->uuid();

    $this->post(uaUrl($setup, 'check-in'), ['collect_now' => true, 'room_charges' => true, 'collect_taxes' => true, 'collect_deposit' => true, 'payment_method_id' => $cash->id, 'idempotency_key' => $key])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $payments = Payment::query()->orderBy('id')->get();

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn)
        ->and($payments->pluck('amount')->all())->toBe([$bill, '100.00'])
        ->and($payments->pluck('purpose')->all())->toBe([PaymentPurpose::Advance, PaymentPurpose::SecurityDeposit])
        ->and($payments->pluck('folio.type')->unique()->all())->toBe([FolioType::Unit]);
});

test('a repeat of the same check-in form takes no payment twice', function () {
    $setup = uaSetup();
    $cash = uaCheckInSetup($setup);
    $form = ['collect_now' => true, 'room_charges' => true, 'collect_deposit' => true, 'payment_method_id' => $cash->id, 'idempotency_key' => fake()->uuid()];

    $this->actingAs($setup['user'])->post(uaUrl($setup, 'check-in'), $form)->assertSessionHasNoErrors();
    $this->post(uaUrl($setup, 'check-in'), $form);

    expect(Payment::query()->count())->toBe(2);
});

test('check in with Room charges and tax on a unit whose rooms go to the Master folio records the bill on the Master folio', function () {
    $setup = uaSetup();
    $cash = uaCheckInSetup($setup);
    $master = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);
    FolioRoutingRule::factory()->forCategory(TransactionCategory::Room)->toFolio($master)->create(['reservation_id' => $setup['reservation']->id]);

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'check-in'), ['collect_now' => true, 'room_charges' => true, 'collect_deposit' => true, 'payment_method_id' => $cash->id, 'idempotency_key' => fake()->uuid()])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $payments = Payment::query()->with('folio')->orderBy('id')->get();

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn)
        ->and($payments->pluck('purpose')->all())->toBe([PaymentPurpose::Advance, PaymentPurpose::SecurityDeposit])
        ->and($payments->pluck('folio.type')->all())->toBe([FolioType::Master, FolioType::Unit]);
});

test('a company billed on Master pays collects no bill at check-in, even when the form sends room_charges', function () {
    $setup = uaSetup();
    $cash = uaCheckInSetup($setup);
    $setup['reservation']->update(['company_id' => Company::factory()->for($setup['property'])->create()->id, 'billing_mode' => BillingMode::MasterPays]);

    $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'check-in-preview'))->assertJsonPath('collection.billHidden', true);

    $this->post(uaUrl($setup, 'check-in'), ['collect_now' => true, 'room_charges' => true, 'collect_deposit' => true, 'payment_method_id' => $cash->id, 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasNoErrors();

    expect(Payment::query()->pluck('purpose')->all())->toBe([PaymentPurpose::SecurityDeposit]);
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
        ->and(Payment::query()->whereNotNull('refund_of_id')->sole()->amount)->toBe('100.00')
        ->and(Folio::query()->where('reservation_unit_id', $setup['unit']->id)->sole()->status)->toBe(FolioStatus::Closed);
});

test('a user with check-out but not folio.close closes the unit folio by checking out', function () {
    $setup = uaDepartingSetup();
    $clerk = User::factory()->for($setup['property'])->withPermissions(['reservations.view', 'front-desk.check-out', 'payment.refund'])->create();

    $this->actingAs($clerk)
        ->post(uaUrl($setup, 'check-out'), ['refund_amount' => '100.00', 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasNoErrors();

    expect(Folio::query()->where('reservation_unit_id', $setup['unit']->id)->sole()->status)->toBe(FolioStatus::Closed);
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
    Role::query()->where('property_id', $setup['user']->property_id)->where('default_role', DefaultRoleEnum::FrontDesk->value)->sole()->revokePermissionTo(PermissionEnum::PaymentRefund->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

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

/**
 * Post a 300.00 charge on the unit folio, so the guest owes 200.00 with the 100.00 deposit held.
 */
function uaOwing(array $setup): void
{
    FolioLine::factory()->create(['folio_id' => Folio::query()->where('reservation_unit_id', $setup['unit']->id)->sole()->id, 'amount' => '300.00', 'unit_amount' => '300.00', 'posting_date' => today()->toDateString()]);
}

test('the check-out preview says who may collect the balance', function () {
    $setup = uaDepartingSetup();
    uaOwing($setup);

    $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'check-out-preview'))
        ->assertJson(['balance' => '200.00', 'owing' => '200.00', 'canCollect' => true]);

    $this->actingAs(User::factory()->for($setup['property'])->withPermissions(['reservations.view', 'front-desk.check-out', 'payment.refund'])->create())
        ->postJson(uaUrl($setup, 'check-out-preview'))
        ->assertJson(['canCollect' => false]);
});

test('check out collects the balance, refunds the deposit and checks the unit out in one step', function () {
    $setup = uaDepartingSetup();
    uaOwing($setup);
    $method = PaymentMethod::query()->sole();

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'check-out'), ['collect_amount' => '300.00', 'payment_method_id' => $method->id, 'refund_amount' => '100.00', 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasNoErrors();

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedOut)
        ->and(Payment::query()->where('purpose', PaymentPurpose::Settlement)->sole()->amount)->toBe('300.00')
        ->and(Payment::query()->whereNotNull('refund_of_id')->sole()->amount)->toBe('100.00')
        ->and(Folio::query()->where('reservation_unit_id', $setup['unit']->id)->sole()->status)->toBe(FolioStatus::Closed);
});

test('check out collects the rest when the deposit is used toward the bill', function () {
    $setup = uaDepartingSetup();
    uaOwing($setup);

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'check-out'), ['collect_amount' => '200.00', 'payment_method_id' => PaymentMethod::query()->sole()->id, 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasNoErrors();

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedOut)
        ->and(Payment::query()->whereNotNull('refund_of_id')->count())->toBe(0);
});

/**
 * The built-in Damage charge item (staff type the amount) on its DAMAGE system code, taxed at 10%.
 *
 * @param  array{property: Property}  $setup
 * @param  array<string, mixed>  $attributes
 */
function uaDamageItem(array $setup, array $attributes = []): ChargeItem
{
    $code = TransactionCode::factory()->for($setup['property'])->system(SystemTransactionCode::DamageRevenue)->create();
    $item = ChargeItem::factory()->for($setup['property'])->for($code)->create(['name' => 'Damage', 'default_amount' => null, ...$attributes]);
    $tax = Tax::factory()->for($setup['property'])->create(['rate' => '10.00', 'is_inclusive' => false, 'foreign_guests_only' => false]);
    $tax->applicableTransactionCodes()->attach($code->id);

    return $item;
}

test('the check-out preview offers the damage item and counts the damage with its tax in the refund', function () {
    $setup = uaDepartingSetup();
    $item = uaDamageItem($setup);

    $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'check-out-preview'), ['damage_amount' => '30.00'])
        ->assertJson(['damageItem' => ['id' => $item->id, 'name' => 'Damage', 'defaultAmount' => null], 'damageTotal' => '33.00', 'refundDefault' => '67.00', 'balance' => '-100.00']);

    $this->actingAs(User::factory()->for($setup['property'])->withPermissions(['reservations.view', 'front-desk.check-out', 'payment.refund'])->create())
        ->postJson(uaUrl($setup, 'check-out-preview'), ['damage_amount' => '30.00'])
        ->assertJson(['damageItem' => null, 'damageTotal' => null]);
});

test('check out posts the damage on the unit folio, refunds the rest of the deposit and checks out', function () {
    $setup = uaDepartingSetup();
    uaDamageItem($setup);
    $key = fake()->uuid();

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'check-out'), ['damage_amount' => '30.00', 'damage_note' => 'Broken lamp', 'refund_amount' => '67.00', 'idempotency_key' => $key])
        ->assertSessionHasNoErrors();

    $folio = Folio::query()->where('reservation_unit_id', $setup['unit']->id)->sole();
    $damage = FolioLine::query()->where('idempotency_key', $key.':damage')->sole();

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedOut)
        ->and($damage->folio_id)->toBe($folio->id)
        ->and($damage->description)->toBe('Damage · Broken lamp')
        ->and($damage->amount)->toBe('30.00')
        ->and($damage->children()->sole()->amount)->toBe('3.00')
        ->and(Payment::query()->whereNotNull('refund_of_id')->sole()->amount)->toBe('67.00')
        ->and($folio->fresh()->status)->toBe(FolioStatus::Closed);
});

test('damage above the deposit refunds nothing and collects the rest', function () {
    $setup = uaDepartingSetup();
    uaDamageItem($setup);

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'check-out'), ['damage_amount' => '250.00', 'collect_amount' => '175.00', 'payment_method_id' => PaymentMethod::query()->sole()->id, 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasNoErrors();

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedOut)
        ->and(Payment::query()->where('purpose', PaymentPurpose::Settlement)->sole()->amount)->toBe('175.00')
        ->and(Payment::query()->whereNotNull('refund_of_id')->count())->toBe(0);
});

test('a damage charge is refused on its field and writes nothing', function (Closure $arrange, Closure $actor, string $amount) {
    $setup = uaDepartingSetup();
    $arrange($setup);

    $this->actingAs($actor($setup))
        ->post(uaUrl($setup, 'check-out'), ['damage_amount' => $amount, 'refund_amount' => '70.00', 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasErrors('damage_amount');

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn)
        ->and(FolioLine::query()->where('idempotency_key', 'like', '%:damage')->count())->toBe(0)
        ->and(Payment::query()->whereNotNull('refund_of_id')->count())->toBe(0);
})->with([
    'the Damage item turned off' => [fn (array $setup) => uaDamageItem($setup, ['active' => false]), fn (array $setup) => $setup['user'], '30.00'],
    'no folio.post-charge' => [fn (array $setup) => uaDamageItem($setup), fn (array $setup) => User::factory()->for($setup['property'])->withPermissions(['reservations.view', 'front-desk.check-out', 'payment.refund'])->create(), '30.00'],
    'a fixed price' => [fn (array $setup) => uaDamageItem($setup, ['default_amount' => '50.00', 'allow_override' => false]), fn (array $setup) => $setup['user'], '30.00'],
]);

test('an amount that no longer settles the folio rolls back the payment and the refund', function () {
    $setup = uaDepartingSetup();
    uaOwing($setup);

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'check-out'), ['collect_amount' => '250.00', 'payment_method_id' => PaymentMethod::query()->sole()->id, 'refund_amount' => '100.00', 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasErrors('form');

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn)
        ->and(Payment::query()->where('purpose', PaymentPurpose::Settlement)->count())->toBe(0)
        ->and(Payment::query()->whereNotNull('refund_of_id')->count())->toBe(0);
});

test('a refused payment goes back to its field and writes nothing', function () {
    $setup = uaDepartingSetup();
    uaOwing($setup);
    $card = PaymentMethod::factory()->create(['property_id' => $setup['property']->id, 'type' => PaymentMethodType::Card, 'requires_reference' => true, 'position' => 2]);

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'check-out'), ['collect_amount' => '300.00', 'payment_method_id' => $card->id, 'refund_amount' => '100.00', 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasErrors('reference');

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn)
        ->and(Payment::query()->whereNotNull('refund_of_id')->count())->toBe(0);
});

test('collect balance from a user without payment.record is dropped', function () {
    $setup = uaDepartingSetup();
    uaOwing($setup);

    $this->actingAs(User::factory()->for($setup['property'])->withPermissions(['reservations.view', 'front-desk.check-out', 'payment.refund'])->create())
        ->post(uaUrl($setup, 'check-out'), ['collect_amount' => '200.00', 'payment_method_id' => PaymentMethod::query()->sole()->id, 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasErrors('form');

    expect(Payment::query()->where('purpose', PaymentPurpose::Settlement)->count())->toBe(0);
});

test('early check-out collects the balance with the fee in one step', function () {
    $setup = uaEarlySetup();
    uaOwing($setup);

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'early-check-out'), ['expected_fee' => '40.00', 'collect_amount' => '240.00', 'payment_method_id' => PaymentMethod::query()->sole()->id, 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasNoErrors();

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedOut)
        ->and(Payment::query()->where('purpose', PaymentPurpose::Settlement)->sole()->amount)->toBe('240.00');
});

test('early check-out prices and posts a damage charge with the fee', function () {
    $setup = uaEarlySetup();
    uaOwing($setup);
    uaDamageItem($setup);
    $key = fake()->uuid();

    $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'check-out-preview'), ['damage_amount' => '30.00'])
        ->assertJson(['early' => ['feeTotal' => '40.00'], 'damageTotal' => '33.00', 'balance' => '200.00']);

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'early-check-out'), ['expected_fee' => '40.00', 'damage_amount' => '30.00', 'collect_amount' => '273.00', 'payment_method_id' => PaymentMethod::query()->sole()->id, 'idempotency_key' => $key])
        ->assertSessionHasNoErrors();

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedOut)
        ->and(FolioLine::query()->where('idempotency_key', $key.':damage')->sole()->amount)->toBe('30.00')
        ->and(Payment::query()->where('purpose', PaymentPurpose::Settlement)->sole()->amount)->toBe('273.00');
});

/**
 * A CheckedIn unit that arrived yesterday and departs today (one night, dated yesterday), while the business date is
 * yesterday: the night audit has not run, and the guest sleeps the night of the business date.
 *
 * @return array{user: User, property: Property, reservation: Reservation, unit: ReservationUnit}
 */
function uaPendingSetup(): array
{
    $setup = uaDepartingSetup(withDeposit: false);
    BusinessDate::query()->update(['date' => today()->subDay()->toDateString()]);

    return $setup;
}

test('the check-out preview includes the pending night in the balance', function () {
    $setup = uaPendingSetup();
    UnitAddOn::factory()->for($setup['unit'], 'reservationUnit')->create(['service_date' => today()->subDay()->toDateString(), 'unit_amount' => '25.00']);

    $preview = $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'check-out-preview'))->assertOk()->json();

    expect(collect($preview['pendingLines'])->pluck('description')->filter(fn ($description): bool => str_starts_with($description, 'Room'))->count())->toBe(1)
        ->and((float) $preview['pendingTotal'])->toBeGreaterThan(25.0)
        ->and($preview['balance'])->toBe($preview['pendingTotal'])
        ->and($preview['owing'])->toBe($preview['pendingTotal'])
        ->and($preview['charges'])->toBe($preview['pendingTotal']);
});

test('check out while the audit is pending posts the night and its add-on with the audit keys', function () {
    $setup = uaPendingSetup();
    $addOn = UnitAddOn::factory()->for($setup['unit'], 'reservationUnit')->create(['service_date' => today()->subDay()->toDateString(), 'unit_amount' => '25.00']);
    $night = $setup['unit']->nights()->sole();
    $owing = $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'check-out-preview'))->json('owing');

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'check-out'), ['collect_amount' => $owing, 'payment_method_id' => PaymentMethod::query()->sole()->id, 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasNoErrors();

    $log = Activity::query()->where('event', 'reservation.unit_checked_out')->sole();

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedOut)
        ->and(Folio::query()->where('reservation_unit_id', $setup['unit']->id)->sole()->status)->toBe(FolioStatus::Closed)
        ->and(FolioLine::query()->where('idempotency_key', 'night:'.$night->id)->sole()->posting_date->toDateString())->toBe(today()->subDay()->toDateString())
        ->and(FolioLine::query()->where('idempotency_key', 'addon:'.$addOn->id)->count())->toBe(1)
        ->and($log->properties['business_date'])->toBe(today()->toDateString());
});

test('a posting that is refused rolls the check-out and the payment back', function () {
    $setup = uaPendingSetup();
    $owing = $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'check-out-preview'))->json('owing');
    app(CreateFolio::class)($setup['reservation'], $setup['unit'])->forceFill(['status' => FolioStatus::Closed])->save();

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'check-out'), ['collect_amount' => $owing, 'payment_method_id' => PaymentMethod::query()->sole()->id, 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasErrors();

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn)
        ->and(Payment::query()->count())->toBe(0)
        ->and(FolioLine::query()->where('idempotency_key', 'like', 'night:%')->count())->toBe(0);
});

test('a unit with no night on the business date posts nothing when it checks out', function () {
    $setup = uaPendingSetup();
    $setup['unit']->forceFill(['arrival_date' => today()->subDays(3)->toDateString(), 'departure_date' => today()->subDay()->toDateString()])->save();
    $setup['unit']->nights()->delete();

    $this->actingAs($setup['user'])->post(uaUrl($setup, 'check-out'), [])->assertSessionHasNoErrors();

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedOut)
        ->and(FolioLine::query()->where('idempotency_key', 'like', 'night:%')->count())->toBe(0);
});

test('an overdue audit still refuses a check-out before the departure date and posts nothing', function () {
    $setup = uaPendingSetup();
    BusinessDate::query()->update(['date' => today()->subDays(2)->toDateString()]);

    $this->actingAs($setup['user'])->post(uaUrl($setup, 'check-out'), [])->assertSessionHasErrors();

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn)
        ->and(FolioLine::query()->where('idempotency_key', 'like', 'night:%')->count())->toBe(0);
});

test('the menu offers check-out and late check-out on the stay day, and late check-out is accepted', function () {
    $setup = uaPendingSetup();
    TransactionCode::factory()->for($setup['property'])->system(SystemTransactionCode::LateCheckoutRevenue)->create();
    RoomRevenuePolicy::factory()->for($setup['property'])->fixed('30.00')->forCode(SystemTransactionCode::LateCheckoutRevenue, PolicyScope::OneCharge)->create();

    $this->actingAs($setup['user'])
        ->get(route('reservations.show', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]))
        ->assertInertia(fn (Assert $page) => $page->where('unit.actions', fn ($actions) => collect($actions)->pluck('key')->intersect(['check-out', 'late-check-out'])->values()->all() === ['check-out', 'late-check-out']));

    $this->actingAs($setup['user'])->post(uaUrl($setup, 'late-check-out'), ['new_time' => '16:00', 'expected_fee' => '30.00'])->assertSessionHasNoErrors();

    expect(substr($setup['unit']->fresh()->departure_time, 0, 5))->toBe('16:00');
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

test('check in is offered for a calendar-date arrival while the audit is pending and not when it is overdue', function () {
    $setup = uaSetup();
    $show = fn () => $this->actingAs($setup['user'])
        ->get(route('reservations.show', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]));

    BusinessDate::query()->update(['date' => today()->subDay()->toDateString()]);
    $show()->assertInertia(fn (Assert $page) => $page->where('unit.actions', fn ($actions) => collect($actions)->pluck('key')->contains('check-in')));

    BusinessDate::query()->update(['date' => today()->subDays(2)->toDateString()]);
    $show()->assertInertia(fn (Assert $page) => $page->where('unit.actions', fn ($actions) => ! collect($actions)->pluck('key')->contains('check-in')));
});

test('check in is not offered before the arrival date or on the departure date', function () {
    $setup = uaSetup(unit: ['arrival_date' => today()->addDay()->toDateString(), 'departure_date' => today()->addDays(3)->toDateString()]);

    $this->actingAs($setup['user'])
        ->get(route('reservations.show', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]))
        ->assertInertia(fn (Assert $page) => $page->where('unit.actions', fn ($actions) => collect($actions)->pluck('key')->all() === ['change-dates', 'cancel', 'no-show', 'move-room']));
});

test('the front desk routes need their own permission', function (string $route) {
    $setup = uaSetup();
    $outsider = User::factory()->for($setup['property'])->withPermissions(['reservations.view'])->create();

    $this->actingAs($outsider)->post(uaUrl($setup, $route), [])->assertForbidden();
})->with(['check-in-preview', 'check-in', 'check-out-preview', 'check-out', 'late-check-out-preview', 'late-check-out']);

/**
 * A second Confirmed unit on the reservation, with its own room unless `$room` is false.
 *
 * @param  array<string, mixed>  $unit
 */
function uaSecondUnit(array $setup, bool $room = true, array $unit = []): ReservationUnit
{
    $first = $setup['unit'];
    $second = ReservationUnit::factory()->for($setup['reservation'])->withNights()->create([
        'room_type_id' => $first->room_type_id,
        'rate_plan_id' => $first->rate_plan_id,
        'arrival_date' => $first->arrival_date->toDateString(),
        'departure_date' => $first->departure_date->toDateString(),
        ...$unit,
    ]);

    if ($room) {
        $second->nights()->update(['room_id' => Room::factory()->for($setup['property'])->create(['room_type_id' => $first->room_type_id])->id]);
    }

    return $second;
}

test('the group preview lists each confirmed unit and whether it is ready', function () {
    $setup = uaSetup();
    uaCheckInSetup($setup);
    $later = uaSecondUnit($setup, unit: ['arrival_date' => today()->addDay()->toDateString(), 'departure_date' => today()->addDays(3)->toDateString()]);
    uaSecondUnit($setup, unit: ['status' => UnitStatus::Cancelled]);
    $noRoom = uaSecondUnit($setup, room: false);

    $this->actingAs($setup['user'])
        ->postJson(route('reservations.check-in-all-preview', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]))
        ->assertOk()
        ->assertJsonCount(3, 'rows')
        ->assertJsonPath('rows.0.ready', true)
        ->assertJsonPath('rows.1.unitId', $later->id)
        ->assertJsonPath('rows.1.ready', false)
        ->assertJsonPath('rows.1.note', 'Cannot check in yet: booked to arrive '.today()->addDay()->format('d M Y').' (tomorrow). To check in today, change the dates first.')
        ->assertJsonPath('rows.2.unitId', $noRoom->id)
        ->assertJsonPath('rows.2.note', 'No room for tonight. Assign a room before check-in.')
        ->assertJsonPath('canCheckInWithoutCard', true);

    expect(Activity::query()->count())->toBe(0);
});

test('check in all checks in what it can and shows each refusal on its own row', function () {
    $setup = uaSetup();
    uaCheckInSetup($setup);
    $ready = uaSecondUnit($setup);
    $noRoom = uaSecondUnit($setup, room: false);
    $foreign = ReservationUnit::factory()->withNights()->create();

    $this->actingAs($setup['user'])
        ->post(route('reservations.check-in-all', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]), ['unit_ids' => [$setup['unit']->id, $ready->id, $noRoom->id, $foreign->id]])
        ->assertRedirect()
        ->assertSessionHasErrors(['units.'.$noRoom->id]);

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn)
        ->and($ready->fresh()->status)->toBe(UnitStatus::CheckedIn)
        ->and($noRoom->fresh()->status)->toBe(UnitStatus::Confirmed)
        ->and($foreign->fresh()->status)->toBe(UnitStatus::Confirmed)
        ->and(Payment::query()->count())->toBe(0);
});

test('check in all closes cleanly when every unit is checked in', function () {
    $setup = uaSetup();
    uaCheckInSetup($setup);
    $second = uaSecondUnit($setup);

    $this->actingAs($setup['user'])
        ->post(route('reservations.check-in-all', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]), ['unit_ids' => [$setup['unit']->id, $second->id]])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(ReservationUnit::query()->where('status', UnitStatus::CheckedIn)->count())->toBe(2);
});

test('check in all drops the card override for a user who may not use it', function () {
    $setup = uaSetup(DefaultRoleEnum::FrontDesk);
    uaCheckInSetup($setup, ['card_required_for_check_in' => true]);
    UnitGuest::factory()->create(['reservation_unit_id' => $setup['unit']->id, 'is_primary' => true]);

    $this->actingAs($setup['user'])
        ->post(route('reservations.check-in-all', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]), ['unit_ids' => [$setup['unit']->id], 'without_card' => true])
        ->assertSessionHasErrors(['units.'.$setup['unit']->id]);

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::Confirmed);
});

test('check in all needs front-desk.check-in and a unit choice', function () {
    $setup = uaSetup();
    $outsider = User::factory()->for($setup['property'])->withPermissions(['reservations.view'])->create();

    $this->actingAs($outsider)->post(route('reservations.check-in-all', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]), ['unit_ids' => [$setup['unit']->id]])->assertForbidden();
    $this->actingAs($outsider)->postJson(route('reservations.check-in-all-preview', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]))->assertForbidden();

    $this->actingAs($setup['user'])->post(route('reservations.check-in-all', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]), ['unit_ids' => []])->assertSessionHasErrors('unit_ids');
});

test('the page tells the browser whether the user may check in', function () {
    $setup = uaSetup();
    $reader = User::factory()->for($setup['property'])->withPermissions(['reservations.view'])->create();
    $url = route('reservations.show', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]);

    $this->actingAs($setup['user'])->get($url)->assertInertia(fn (Assert $page) => $page->where('canCheckIn', true));
    $this->actingAs($reader)->get($url)->assertInertia(fn (Assert $page) => $page->where('canCheckIn', false));
});

/**
 * A checked-in unit that arrived yesterday and departs in two days, with a 100.00 security deposit held on its folio.
 * The early departure policy is 40.00 on the unused nights, so leaving today costs 40.00.
 *
 * @return array{user: User, property: Property, reservation: Reservation, unit: ReservationUnit}
 */
function uaEarlySetup(DefaultRoleEnum $role = DefaultRoleEnum::Owner, bool $withDeposit = true): array
{
    $setup = uaSetup($role, ['status' => UnitStatus::CheckedIn, 'checked_in_at' => now()->subDay(), 'arrival_date' => today()->subDay()->toDateString(), 'departure_date' => today()->addDays(2)->toDateString()]);
    $cash = uaCheckInSetup($setup);

    if ($withDeposit) {
        app(RecordPayment::class)($setup['user'], $setup['reservation'], null, $setup['unit'], new NewPaymentData($cash->id, PaymentPurpose::SecurityDeposit, '100.00', null, fake()->uuid()));
    }

    return $setup;
}

function uaEarlyFeeLines(ReservationUnit $unit): int
{
    return FolioLine::query()->where('idempotency_key', 'like', 'early_departure:'.$unit->id.':%')->count();
}

test('the check-out preview of an early departure shows the fee and the nights it removes', function () {
    $setup = uaEarlySetup();

    $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'check-out-preview'))
        ->assertOk()
        ->assertJson(['balance' => '-100.00', 'early' => ['departure' => today()->addDays(2)->toDateString(), 'nightsRemoved' => 2, 'fee' => '40.00', 'feeTotal' => '40.00', 'withinFreeWindow' => false]]);
});

test('the check-in and early check-out previews run with lazy loading prevented', function () {
    $checkIn = uaSetup();
    uaCheckInSetup($checkIn);
    $early = uaEarlySetup();

    Model::preventLazyLoading();

    try {
        $this->actingAs($checkIn['user'])->postJson(uaUrl($checkIn, 'check-in-preview'))->assertOk();
        $this->actingAs($early['user'])->postJson(uaUrl($early, 'check-out-preview'))->assertOk()->assertJsonPath('early.fee', '40.00');
    } finally {
        Model::preventLazyLoading(false);
    }
});

test('a unit leaving on its departure date has no early block', function () {
    $setup = uaDepartingSetup();

    $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'check-out-preview'))
        ->assertJsonPath('early', null);
});

test('early check-out shortens the stay, posts the fee, refunds the deposit and checks the unit out', function () {
    $setup = uaEarlySetup();

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'early-check-out'), ['refund_amount' => '60.00', 'expected_fee' => '40.00', 'idempotency_key' => fake()->uuid()])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $unit = $setup['unit']->fresh();

    expect($unit->status)->toBe(UnitStatus::CheckedOut)
        ->and($unit->departure_date->toDateString())->toBe(today()->toDateString())
        ->and($unit->nights()->count())->toBe(1)
        ->and(uaEarlyFeeLines($unit))->toBe(1)
        ->and(Payment::query()->whereNotNull('refund_of_id')->sole()->amount)->toBe('60.00')
        ->and(Activity::query()->where('event', 'reservation.unit_dates_changed')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'reservation.unit_checked_out')->count())->toBe(1)
        ->and(Folio::query()->where('reservation_unit_id', $unit->id)->sole()->status)->toBe(FolioStatus::Closed);
});

test('a refused check-out also undoes the fee and the shorter stay', function () {
    $setup = uaEarlySetup(withDeposit: false);

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'early-check-out'), [])
        ->assertSessionHasErrors('form');

    $unit = $setup['unit']->fresh();

    expect($unit->status)->toBe(UnitStatus::CheckedIn)
        ->and($unit->departure_date->toDateString())->toBe(today()->addDays(2)->toDateString())
        ->and($unit->nights()->count())->toBe(3)
        ->and(uaEarlyFeeLines($unit))->toBe(0)
        ->and(Activity::query()->where('event', 'reservation.unit_dates_changed')->count())->toBe(0);
});

test('a refund above what is held leaves the stay as it was', function () {
    $setup = uaEarlySetup();

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'early-check-out'), ['refund_amount' => '150.00', 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasErrors('refund_amount');

    expect($setup['unit']->fresh()->departure_date->toDateString())->toBe(today()->addDays(2)->toDateString())
        ->and(uaEarlyFeeLines($setup['unit']))->toBe(0);
});

test('early check-out with Do not charge posts no fee and needs a reason', function () {
    $setup = uaEarlySetup();

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'early-check-out'), ['do_not_charge' => true, 'refund_amount' => '100.00', 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasErrors('reason');

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'early-check-out'), ['do_not_charge' => true, 'reason' => 'Family emergency', 'refund_amount' => '100.00', 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasNoErrors();

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedOut)
        ->and(uaEarlyFeeLines($setup['unit']))->toBe(0);
});

test('a fee that changed since the form opened is refused and writes nothing', function () {
    $setup = uaEarlySetup();
    RoomRevenuePolicy::query()->where('code', SystemTransactionCode::EarlyDepartureRevenue)->update(['amount' => '55.00']);

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'early-check-out'), ['expected_fee' => '40.00', 'refund_amount' => '60.00', 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasErrors('expected_fee');

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn);
});

test('a unit that arrived today cannot check out early', function () {
    $setup = uaSetup(unit: ['status' => UnitStatus::CheckedIn, 'checked_in_at' => now(), 'arrival_date' => today()->toDateString(), 'departure_date' => today()->addDays(2)->toDateString()]);

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'early-check-out'), [])
        ->assertSessionHasErrors('form');

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn);
});

test('a unit on its departure date is sent to the normal check-out', function () {
    $setup = uaDepartingSetup(withDeposit: false);

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'early-check-out'), [])
        ->assertSessionHasErrors('form');

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn);
});

test('the menu offers check-out before the departure date only to staff who can change dates', function () {
    $setup = uaEarlySetup();
    $showUrl = route('reservations.show', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]);

    $this->actingAs($setup['user'])->get($showUrl)
        ->assertInertia(fn (Assert $page) => $page->where('unit.actions', fn ($actions) => collect($actions)->pluck('key')->all() === ['check-out', 'change-dates', 'move-room']));

    $checkOutOnly = User::factory()->for($setup['property'])->withPermissions(['reservations.view', 'front-desk.check-out'])->create();

    $this->actingAs($checkOutOnly)->get($showUrl)
        ->assertInertia(fn (Assert $page) => $page->where('unit.actions', []));
});

test('early check-out needs both front-desk.check-out and reservations.manage', function (array $permissions) {
    $setup = uaEarlySetup();

    $this->actingAs(User::factory()->for($setup['property'])->withPermissions($permissions)->create())->post(uaUrl($setup, 'early-check-out'), [])->assertForbidden();

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn);
})->with([
    'check-out only' => [['reservations.view', 'front-desk.check-out']],
    'manage only' => [['reservations.view', 'reservations.manage']],
]);

test('check-in with only an exempt tax and no deposit takes no payment', function () {
    $setup = uaSetup();
    $cash = uaCheckInSetup($setup, ['security_deposit_amount' => '0.00']);
    Tax::query()->where('property_id', $setup['property']->id)->update(['foreign_guests_only' => true]);
    UnitGuest::factory()->create(['reservation_unit_id' => $setup['unit']->id, 'is_primary' => true])->guest->update(['nationality_code' => 'MY']);

    $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'check-in-preview'))
        ->assertJsonPath('collection.taxesTotal', '0.00')
        ->assertJsonPath('collection.deposit', null)
        ->assertJsonPath('collection.taxes.0.exempt', true);

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'check-in'), ['collect_now' => true, 'collect_taxes' => true, 'payment_method_id' => $cash->id, 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasNoErrors();

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn)
        ->and(Payment::query()->count())->toBe(0);
});

test('the check-in preview tells whether tonight has a room, will get one or needs one', function (bool $assigned, bool $autoAssign, string $state) {
    $setup = uaSetup();
    uaCheckInSetup($setup, ['auto_assign_rooms' => $autoAssign]);

    if (! $assigned) {
        $setup['unit']->nights()->update(['room_id' => null]);
    }

    $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'check-in-preview'))
        ->assertOk()
        ->assertJsonPath('roomState', $state)
        ->assertJsonPath('room', $assigned ? Room::query()->firstOrFail()->number : null);
})->with([
    'assigned' => [true, false, 'assigned'],
    'assigned with auto assign on' => [true, true, 'assigned'],
    'no room, auto assign on' => [false, true, 'auto'],
    'no room, auto assign off' => [false, false, 'none'],
]);

/**
 * A checked-in unit that arrived on the business date and departs in two days: it has no night to keep.
 *
 * @return array{user: User, property: Property, reservation: Reservation, unit: ReservationUnit}
 */
function uaArrivedTodaySetup(): array
{
    $setup = uaSetup(DefaultRoleEnum::Owner, ['status' => UnitStatus::CheckedIn, 'checked_in_at' => now(), 'arrival_date' => today()->toDateString(), 'departure_date' => today()->addDays(2)->toDateString()]);
    uaCheckInSetup($setup);

    return $setup;
}

test('a unit that arrived today has no early check-out in the menu, the preview or the Action', function () {
    $setup = uaArrivedTodaySetup();
    $message = 'This unit arrived today, so there is no night to remove. It can check out on its departure date. If it checked in by mistake, use Undo check-in.';

    $this->actingAs($setup['user'])
        ->get(route('reservations.show', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]))
        ->assertInertia(fn (Assert $page) => $page->where('unit.actions', fn ($actions) => ! collect($actions)->pluck('key')->contains('check-out')));

    $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'check-out-preview'))
        ->assertOk()
        ->assertJsonPath('error', $message)
        ->assertJsonPath('early', null);

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'early-check-out'), ['idempotency_key' => fake()->uuid()])
        ->assertSessionHasErrors(['form' => $message]);

    $unit = $setup['unit']->fresh();

    expect($unit->status)->toBe(UnitStatus::CheckedIn)
        ->and($unit->departure_date->toDateString())->toBe(today()->addDays(2)->toDateString())
        ->and($unit->nights()->count())->toBe(2)
        ->and(uaEarlyFeeLines($unit))->toBe(0)
        ->and(Activity::query()->whereIn('event', ['reservation.unit_dates_changed', 'reservation.unit_checked_out'])->count())->toBe(0);
});

test('a unit that arrived yesterday is still offered an early check-out', function () {
    $setup = uaEarlySetup();

    $this->actingAs($setup['user'])
        ->get(route('reservations.show', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]))
        ->assertInertia(fn (Assert $page) => $page->where('unit.actions', fn ($actions) => collect($actions)->pluck('key')->contains('check-out')));
});

test('early check-out is refused while the night audit is overdue and writes nothing', function () {
    $setup = uaEarlySetup();
    $departure = today()->addDays(2)->toDateString();
    $this->travelTo(now()->addDays(2));
    $message = 'The night audit for '.today()->subDays(2)->format('j M').' has not run. Run it first, so the night of '.today()->subDays(2)->format('j M').' is charged.';

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'early-check-out'), ['expected_fee' => '40.00', 'refund_amount' => '60.00', 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasErrors(['form' => $message]);

    $unit = $setup['unit']->fresh();

    expect($unit->status)->toBe(UnitStatus::CheckedIn)
        ->and($unit->departure_date->toDateString())->toBe($departure)
        ->and($unit->nights()->count())->toBe(3)
        ->and(uaEarlyFeeLines($unit))->toBe(0)
        ->and(Activity::query()->whereIn('event', ['reservation.unit_dates_changed', 'reservation.unit_checked_out'])->count())->toBe(0);
});

test('early check-out passes when the property calendar date is the business date', function () {
    $setup = uaEarlySetup();

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'early-check-out'), ['expected_fee' => '40.00', 'refund_amount' => '60.00', 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasNoErrors();

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedOut);
});

test('the early check-out preview carries the night audit message while the audit is overdue', function () {
    $setup = uaEarlySetup();
    $this->travelTo(now()->addDays(2));

    $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'check-out-preview'))
        ->assertOk()
        ->assertJsonPath('error', 'The night audit for '.today()->subDays(2)->format('j M').' has not run. Run it first, so the night of '.today()->subDays(2)->format('j M').' is charged.')
        ->assertJsonPath('early', null);
});

/**
 * An early departure set-up while the audit is pending: the unit arrived on the business date (yesterday) and departs in
 * two days, with no deposit. The calendar date is today.
 *
 * @return array{user: User, property: Property, reservation: Reservation, unit: ReservationUnit}
 */
function uaEarlyPendingSetup(): array
{
    $setup = uaEarlySetup(withDeposit: false);
    BusinessDate::query()->update(['date' => today()->subDay()->toDateString()]);

    return $setup;
}

test('early check-out while the audit is pending keeps and posts the night of the business date and removes the rest', function () {
    $setup = uaEarlyPendingSetup();
    $addOn = UnitAddOn::factory()->for($setup['unit'], 'reservationUnit')->create(['service_date' => today()->subDay()->toDateString(), 'unit_amount' => '25.00']);
    $later = UnitAddOn::factory()->for($setup['unit'], 'reservationUnit')->create(['service_date' => today()->addDay()->toDateString(), 'unit_amount' => '25.00']);
    $night = $setup['unit']->nights()->whereDate('date', today()->subDay()->toDateString())->sole();

    $preview = $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'check-out-preview'))->assertOk()->json();
    $collect = (string) BigDecimal::of($preview['owing'])->plus($preview['early']['feeTotal'])->toScale(2);

    expect($preview['early']['newDeparture'])->toBe(today()->toDateString())
        ->and($preview['early']['nightsRemoved'])->toBe(2)
        ->and($preview['early']['leftYesterdayAvailable'])->toBeFalse()
        ->and($preview['pendingLines'])->not->toBeEmpty();

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'early-check-out'), ['expected_fee' => '40.00', 'collect_amount' => $collect, 'payment_method_id' => PaymentMethod::query()->sole()->id, 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasNoErrors();

    $unit = $setup['unit']->fresh();

    expect($unit->status)->toBe(UnitStatus::CheckedOut)
        ->and($unit->departure_date->toDateString())->toBe(today()->toDateString())
        ->and($unit->nights()->pluck('id')->all())->toBe([$night->id])
        ->and(FolioLine::query()->where('idempotency_key', 'night:'.$night->id)->count())->toBe(1)
        ->and(FolioLine::query()->where('idempotency_key', 'addon:'.$addOn->id)->count())->toBe(1)
        ->and(UnitAddOn::query()->whereKey($later->id)->exists())->toBeFalse()
        ->and(uaEarlyFeeLines($unit))->toBe(1)
        ->and(Folio::query()->where('reservation_unit_id', $unit->id)->sole()->status)->toBe(FolioStatus::Closed);
});

test('the previews carry the pending-audit strip and the line under the stay only while the audit is pending', function () {
    $day = fn (int $offset): string => today()->addDays($offset)->format('j M');
    $setup = uaPendingSetup();

    $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'check-out-preview'))
        ->assertJsonPath('auditPending', ['businessDate' => $day(-1), 'today' => $day(0), 'running' => false, 'notice' => 'Last night ('.$day(-1).') is added to the bill now. The night audit will skip it.']);

    BusinessDate::query()->update(['status' => BusinessDateStatus::Auditing]);
    $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'check-out-preview'))->assertJsonPath('auditPending.running', true);
    BusinessDate::query()->update(['status' => BusinessDateStatus::Open]);

    $early = uaEarlyPendingSetup();

    $this->actingAs($early['user'])->postJson(uaUrl($early, 'check-out-preview'))
        ->assertJsonPath('auditPending.notice', 'Last night ('.$day(-1).') is added to the bill now. The night audit will skip it. '.$day(0).' and '.$day(1).' removed.');

    $arrival = uaSetup();
    BusinessDate::query()->update(['date' => today()->subDay()->toDateString()]);
    $this->actingAs($arrival['user'])->postJson(uaUrl($arrival, 'check-in-preview'))
        ->assertJsonPath('auditPending.notice', 'That\'s fine. Tonight\'s night audit charges '.$day(0).'.');

    BusinessDate::query()->update(['date' => today()->toDateString()]);
    $this->actingAs($arrival['user'])->postJson(uaUrl($arrival, 'check-in-preview'))->assertJsonPath('auditPending', null);
});

test('the menu offers an early check-out while the audit is pending', function () {
    $setup = uaEarlyPendingSetup();

    $this->actingAs($setup['user'])
        ->get(route('reservations.show', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]))
        ->assertInertia(fn (Assert $page) => $page->where('unit.actions', fn ($actions) => collect($actions)->pluck('key')->contains('check-out')));
});

/**
 * A checked-in unit that arrived two days ago and departs in two days, while the business date is yesterday: it can
 * still be checked out as having left yesterday.
 *
 * @return array{user: User, property: Property, reservation: Reservation, unit: ReservationUnit}
 */
function uaLeftYesterdaySetup(): array
{
    $setup = uaSetup(unit: ['status' => UnitStatus::CheckedIn, 'checked_in_at' => now()->subDays(2), 'arrival_date' => today()->subDays(2)->toDateString(), 'departure_date' => today()->addDays(2)->toDateString()]);
    uaCheckInSetup($setup);
    BusinessDate::query()->update(['date' => today()->subDay()->toDateString()]);

    return $setup;
}

test('guest actually left yesterday sets the departure to the business date, posts no night and charges the fee', function () {
    $setup = uaLeftYesterdaySetup();
    $firstNight = $setup['unit']->nights()->whereDate('date', today()->subDays(2)->toDateString())->sole();
    $preview = $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'check-out-preview'), ['left_yesterday' => true])->assertOk()->json();
    $collect = (string) BigDecimal::of($preview['owing'])->plus($preview['early']['feeTotal'])->toScale(2);

    expect($preview['early']['newDeparture'])->toBe(today()->subDay()->toDateString())
        ->and($preview['early']['nightsRemoved'])->toBe(3)
        ->and($preview['pendingLines'])->toBe([]);

    $payload = ['left_yesterday' => true, 'expected_fee' => '40.00', 'collect_amount' => $collect, 'payment_method_id' => PaymentMethod::query()->sole()->id, 'idempotency_key' => fake()->uuid()];

    $this->actingAs($setup['user'])->post(uaUrl($setup, 'early-check-out'), $payload)->assertSessionHasErrors('reason');

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn);

    $this->actingAs($setup['user'])->post(uaUrl($setup, 'early-check-out'), [...$payload, 'reason' => 'Left at night'])->assertSessionHasNoErrors();

    $unit = $setup['unit']->fresh();

    expect($unit->status)->toBe(UnitStatus::CheckedOut)
        ->and($unit->departure_date->toDateString())->toBe(today()->subDay()->toDateString())
        ->and($unit->nights()->pluck('id')->all())->toBe([$firstNight->id])
        ->and(FolioLine::query()->where('idempotency_key', 'like', 'night:%')->count())->toBe(0)
        ->and(uaEarlyFeeLines($unit))->toBe(1)
        ->and(Activity::query()->where('event', 'reservation.unit_dates_changed')->sole()->properties['reason'])->toBe('Left at night')
        ->and(Activity::query()->where('event', 'reservation.unit_checked_out')->sole()->properties['business_date'])->toBe(today()->subDay()->toDateString());
});

test('guest actually left yesterday is refused for a unit that arrived on the business date', function () {
    $setup = uaEarlyPendingSetup();

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'early-check-out'), ['left_yesterday' => true, 'reason' => 'Left at night'])
        ->assertSessionHasErrors(['form' => 'This unit arrived today, so there is no night to remove. It can check out on its departure date. If it checked in by mistake, use Undo check-in.']);

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn);
});

test('guest actually left yesterday is refused when the audit is not pending', function () {
    $setup = uaEarlySetup();

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'early-check-out'), ['left_yesterday' => true, 'reason' => 'Left at night'])
        ->assertSessionHasErrors('form');

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn)
        ->and(uaEarlyFeeLines($setup['unit']))->toBe(0);
});

/**
 * A checked-in unit that arrived two days ago and departs today, the stay day, while the business date is yesterday and
 * the audit is pending. A plain check-out would charge the night of the business date.
 *
 * @return array{user: User, property: Property, reservation: Reservation, unit: ReservationUnit}
 */
function uaLeftYesterdayOnStayDaySetup(): array
{
    $setup = uaSetup(unit: ['status' => UnitStatus::CheckedIn, 'checked_in_at' => now()->subDays(2), 'arrival_date' => today()->subDays(2)->toDateString(), 'departure_date' => today()->toDateString()]);
    uaCheckInSetup($setup);
    BusinessDate::query()->update(['date' => today()->subDay()->toDateString()]);

    return $setup;
}

test('a plain check-out on the stay day offers They left yesterday while the audit is pending', function () {
    $setup = uaLeftYesterdayOnStayDaySetup();

    $preview = $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'check-out-preview'))->assertOk()->json();

    expect($preview['early'])->toBeNull()
        ->and($preview['leftYesterdayChoice'])->toBeTrue()
        ->and($preview['leftYesterdayBlocked'])->toBeNull()
        ->and($preview['pendingLines'])->not->toBeEmpty();
});

test('They left yesterday on a stay day departure posts no night and removes the unslept night', function () {
    $setup = uaLeftYesterdayOnStayDaySetup();
    $firstNight = $setup['unit']->nights()->whereDate('date', today()->subDays(2)->toDateString())->sole();

    $preview = $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'check-out-preview'), ['left_yesterday' => true])->assertOk()->json();

    expect($preview['early']['newDeparture'])->toBe(today()->subDay()->toDateString())
        ->and($preview['early']['nightsRemoved'])->toBe(1)
        ->and($preview['leftYesterdayChoice'])->toBeTrue()
        ->and($preview['leftYesterdayBlocked'])->toBeNull()
        ->and($preview['pendingLines'])->toBe([]);

    $collect = (string) BigDecimal::of($preview['owing'])->plus($preview['early']['feeTotal'])->toScale(2);
    $payload = ['left_yesterday' => true, 'expected_fee' => $preview['early']['fee'], 'collect_amount' => $collect, 'payment_method_id' => PaymentMethod::query()->sole()->id, 'idempotency_key' => fake()->uuid()];

    $this->actingAs($setup['user'])->post(uaUrl($setup, 'early-check-out'), $payload)->assertSessionHasErrors('reason');
    $this->actingAs($setup['user'])->post(uaUrl($setup, 'early-check-out'), [...$payload, 'reason' => 'Left at night'])->assertSessionHasNoErrors();

    $unit = $setup['unit']->fresh();

    expect($unit->status)->toBe(UnitStatus::CheckedOut)
        ->and($unit->departure_date->toDateString())->toBe(today()->subDay()->toDateString())
        ->and($unit->nights()->pluck('id')->all())->toBe([$firstNight->id])
        ->and(FolioLine::query()->where('idempotency_key', 'like', 'night:%')->count())->toBe(0);
});

test('They left yesterday shows disabled for a guest who arrived on the business date', function () {
    $setup = uaEarlyPendingSetup();

    $preview = $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'check-out-preview'))->assertOk()->json();

    expect($preview['early']['newDeparture'])->toBe(today()->toDateString())
        ->and($preview['leftYesterdayChoice'])->toBeTrue()
        ->and($preview['leftYesterdayBlocked'])->toContain('no night to remove');
});

test('They left yesterday is not offered for a guest due to leave on the business date or when the audit is not pending', function () {
    $overdue = uaOverdueSetup();
    $inSync = uaSetup(unit: ['status' => UnitStatus::CheckedIn, 'checked_in_at' => now()->subDays(2), 'arrival_date' => today()->subDays(2)->toDateString(), 'departure_date' => today()->toDateString()]);
    uaCheckInSetup($inSync);

    expect($this->actingAs($overdue['user'])->postJson(uaUrl($overdue, 'check-out-preview'))->assertOk()->json('leftYesterdayChoice'))->toBeFalse()
        ->and($this->actingAs($inSync['user'])->postJson(uaUrl($inSync, 'check-out-preview'))->assertOk()->json('leftYesterdayChoice'))->toBeFalse();
});

/**
 * A CheckedIn unit that was due to leave yesterday, the business date, while the calendar date is today: the night audit
 * has not run. It slept one night, two days ago. The plan has a rate for the night of the business date.
 *
 * @return array{user: User, property: Property, reservation: Reservation, unit: ReservationUnit}
 */
function uaOverdueSetup(): array
{
    $setup = uaSetup(unit: ['status' => UnitStatus::CheckedIn, 'checked_in_at' => now()->subDays(2), 'arrival_date' => today()->subDays(2)->toDateString(), 'departure_date' => today()->subDay()->toDateString()]);
    uaCheckInSetup($setup);
    BusinessDate::query()->update(['date' => today()->subDay()->toDateString()]);
    Rate::factory()->create(['rate_plan_id' => $setup['unit']->rate_plan_id, 'room_type_id' => $setup['unit']->room_type_id, 'date' => today()->subDay()->toDateString(), 'amount' => '120.00']);

    return $setup;
}

test('the check-out preview of an overdue guest offers the night they would add', function () {
    $setup = uaOverdueSetup();

    $left = $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'check-out-preview'))->assertOk()->json();
    $stayed = $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'check-out-preview'), ['stayed_last_night' => true])->assertOk()->json();

    expect($left['overdue']['nightDate'])->toBe(today()->subDay()->toDateString())
        ->and($left['overdue']['nightTotal'])->toBe('129.60')
        ->and($left['balance'])->toBe('0.00')
        ->and($left['overdue']['balanceIfStayed'])->toBe('129.60')
        ->and($stayed['balance'])->toBe('129.60')
        ->and($stayed['owing'])->toBe('129.60')
        ->and($stayed['pendingTotal'])->toBe('129.60');
});

test('they left yesterday is a normal check-out on the business date with no extra night', function () {
    $setup = uaOverdueSetup();

    $this->actingAs($setup['user'])->post(uaUrl($setup, 'check-out'), [])->assertSessionHasNoErrors();

    $unit = $setup['unit']->fresh();

    expect($unit->status)->toBe(UnitStatus::CheckedOut)
        ->and($unit->departure_date->toDateString())->toBe(today()->subDay()->toDateString())
        ->and(FolioLine::query()->where('idempotency_key', 'like', 'night:%')->count())->toBe(0)
        ->and(Activity::query()->where('event', 'reservation.unit_checked_out')->sole()->properties['business_date'])->toBe(today()->subDay()->toDateString());
});

test('they stayed last night adds the night and its add-on, checks out and leaves the audit nothing to post', function () {
    $setup = uaOverdueSetup();
    $addOn = UnitAddOn::factory()->for($setup['unit'], 'reservationUnit')->create(['service_date' => today()->subDay()->toDateString(), 'unit_amount' => '25.00']);
    $preview = $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'check-out-preview'), ['stayed_last_night' => true])->assertOk()->json();

    expect((float) $preview['owing'])->toBeGreaterThan(129.6);

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'check-out'), ['stayed_last_night' => true, 'collect_amount' => $preview['owing'], 'payment_method_id' => PaymentMethod::query()->sole()->id, 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasNoErrors();

    $unit = $setup['unit']->fresh();
    $night = $unit->nights()->whereDate('date', today()->subDay()->toDateString())->sole();

    expect($unit->status)->toBe(UnitStatus::CheckedOut)
        ->and($unit->departure_date->toDateString())->toBe(today()->toDateString())
        ->and(FolioLine::query()->where('idempotency_key', 'night:'.$night->id)->count())->toBe(1)
        ->and(FolioLine::query()->where('idempotency_key', 'addon:'.$addOn->id)->count())->toBe(1)
        ->and(Folio::query()->where('reservation_unit_id', $unit->id)->sole()->status)->toBe(FolioStatus::Closed)
        ->and(Activity::query()->where('event', 'reservation.unit_dates_changed')->sole()->properties['restriction_override_reason'])->toBe('Guest stayed the night');
});

test('a restricted night passes with the fixed reason', function () {
    $setup = uaOverdueSetup();
    Rate::query()->whereDate('date', today()->subDay()->toDateString())->update(['stop_sell' => true]);
    $owing = $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'check-out-preview'), ['stayed_last_night' => true])->json('owing');

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'check-out'), ['stayed_last_night' => true, 'collect_amount' => $owing, 'payment_method_id' => PaymentMethod::query()->sole()->id, 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasNoErrors();

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedOut);
});

test('a night booked by another guest refuses they stayed and writes nothing', function () {
    $setup = uaOverdueSetup();
    ReservationUnit::factory()->for(Reservation::factory()->create(['property_id' => $setup['property']->id]))->withNights()->create([
        'room_type_id' => $setup['unit']->room_type_id,
        'rate_plan_id' => $setup['unit']->rate_plan_id,
        'arrival_date' => today()->subDay()->toDateString(),
        'departure_date' => today()->toDateString(),
    ]);
    $room = Room::query()->whereKey($setup['unit']->nights()->value('room_id'))->value('number');
    $owing = '129.60';

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'check-out'), ['stayed_last_night' => true, 'collect_amount' => $owing, 'payment_method_id' => PaymentMethod::query()->sole()->id, 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasErrors(['form' => 'Room '.$room.' is booked by another guest for '.today()->subDay()->format('j M').'. Move one of them first.']);

    $unit = $setup['unit']->fresh();

    expect($unit->status)->toBe(UnitStatus::CheckedIn)
        ->and($unit->departure_date->toDateString())->toBe(today()->subDay()->toDateString())
        ->and(Payment::query()->count())->toBe(0)
        ->and(FolioLine::query()->count())->toBe(0);
});

test('they stayed is refused for a unit that is not an overdue guest, and needs reservations.manage', function () {
    $setup = uaPendingSetup();

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'check-out'), ['stayed_last_night' => true])
        ->assertSessionHasErrors(['form' => 'This guest was not due to leave yesterday.']);

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn);

    $overdue = uaOverdueSetup();
    $clerk = User::factory()->for($overdue['property'])->withPermissions(['reservations.view', 'front-desk.check-out', 'payment.record'])->create();

    $this->actingAs($clerk)->post(uaUrl($overdue, 'check-out'), ['stayed_last_night' => true])->assertSessionHasErrors('stayed_last_night');

    expect($overdue['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn);
});

test('a repeated they stayed request with the same key writes nothing more', function () {
    $setup = uaOverdueSetup();
    $key = fake()->uuid();
    $payload = ['stayed_last_night' => true, 'collect_amount' => '129.60', 'payment_method_id' => PaymentMethod::query()->sole()->id, 'idempotency_key' => $key];

    $this->actingAs($setup['user'])->post(uaUrl($setup, 'check-out'), $payload)->assertSessionHasNoErrors();
    $lines = FolioLine::query()->count();
    $this->actingAs($setup['user'])->post(uaUrl($setup, 'check-out'), $payload);

    expect(FolioLine::query()->count())->toBe($lines)
        ->and(Payment::query()->count())->toBe(1)
        ->and($setup['unit']->fresh()->departure_date->toDateString())->toBe(today()->toDateString());
});

/**
 * A checked-in unit that arrived today, with a 100.00 deposit held on its folio.
 *
 * @return array{user: User, property: Property, reservation: Reservation, unit: ReservationUnit}
 */
function uaUndoSetup(): array
{
    $setup = uaArrivedTodaySetup();
    $cash = PaymentMethod::query()->firstOrFail();
    app(RecordPayment::class)($setup['user'], $setup['reservation'], null, $setup['unit'], new NewPaymentData($cash->id, PaymentPurpose::SecurityDeposit, '100.00', null, fake()->uuid()));

    return $setup;
}

test('undo check-in puts the unit back to Confirmed, keeps the money and the room, and logs the reason', function () {
    $setup = uaUndoSetup();
    $roomId = $setup['unit']->nights()->value('room_id');

    $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'undo-check-in-preview'))
        ->assertOk()
        ->assertJsonPath('error', null)
        ->assertJsonPath('paid', '100.00');

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'undo-check-in'), ['reason' => 'Wrong guest checked in'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $unit = $setup['unit']->fresh();
    $log = Activity::query()->where('event', 'reservation.unit_check_in_undone')->sole();

    expect($unit->status)->toBe(UnitStatus::Confirmed)
        ->and($unit->checked_in_at)->toBeNull()
        ->and($unit->nights()->value('room_id'))->toBe($roomId)
        ->and(Payment::query()->count())->toBe(1)
        ->and($log->properties['reason'])->toBe('Wrong guest checked in')
        ->and($log->causer_id)->toBe($setup['user']->id)
        ->and($setup['reservation']->fresh()->status)->toBe(ReservationStatus::Confirmed);
});

test('undo check-in needs a reason', function () {
    $setup = uaUndoSetup();

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'undo-check-in'), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn)
        ->and(Activity::query()->where('event', 'reservation.unit_check_in_undone')->count())->toBe(0);
});

test('undo check-in is refused once the night audit has run since the check-in', function () {
    $setup = uaUndoSetup();
    $setup['unit']->forceFill(['checked_in_at' => now()->subDay()])->save();

    $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'undo-check-in-preview'))
        ->assertJsonPath('error', 'The business date has changed since this was done, so it cannot be undone.');

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'undo-check-in'), ['reason' => 'Mistake'])
        ->assertSessionHasErrors('form');

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn);
});

test('undo check-in is refused by a posted night, an inclusion line or a day use block, and not by another unit\'s', function (string $key, bool $refused) {
    $setup = uaUndoSetup();
    $night = $setup['unit']->nights()->first();
    $other = $setup['unit']->nights()->latest('id')->first();
    $replace = ['{night}' => $night->id, '{unit}' => $setup['unit']->id, '{foreign}' => $other->id + 1000];
    FolioLine::factory()->create(['folio_id' => Folio::query()->firstOrFail()->id, 'idempotency_key' => strtr($key, $replace)]);

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'undo-check-in'), ['reason' => 'Mistake']);

    expect($setup['unit']->fresh()->status)->toBe($refused ? UnitStatus::CheckedIn : UnitStatus::Confirmed);
})->with([
    'night' => ['night:{night}', true],
    'inclusion' => ['night:{night}:incl:7', true],
    'fix' => ['night:{night}:fix:1', true],
    'day use' => ['dayuse:{unit}', true],
    'another unit' => ['night:{foreign}', false],
]);

test('a re-check-in after an undo does not post an add-on twice', function () {
    $setup = uaUndoSetup();
    $addOn = UnitAddOn::factory()->create(['reservation_unit_id' => $setup['unit']->id, 'service_date' => null, 'folio_line_id' => null]);

    app(UndoCheckIn::class)($setup['user'], $setup['unit']->fresh(), 'Wrong room');
    $first = app(CheckInUnit::class)($setup['user'], $setup['unit']->fresh());
    $linesAfterFirst = FolioLine::query()->whereNull('parent_line_id')->count();

    app(UndoCheckIn::class)($setup['user'], $setup['unit']->fresh(), 'Wrong room again');
    app(CheckInUnit::class)($setup['user'], $setup['unit']->fresh());

    expect($first->isRefused())->toBeFalse()
        ->and($addOn->fresh()->folio_line_id)->not->toBeNull()
        ->and(FolioLine::query()->whereNull('parent_line_id')->count())->toBe($linesAfterFirst);
});

test('undo check-in is in the menu for Owner and Manager only, and its routes need the permission', function () {
    $setup = uaUndoSetup();
    $clerk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create(['property_id' => $setup['property']->id]);
    $showUrl = route('reservations.show', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]);

    $this->actingAs($setup['user'])->get($showUrl)
        ->assertInertia(fn (Assert $page) => $page->where('unit.actions', fn ($actions) => collect($actions)->pluck('key')->contains('undo-check-in')));

    $this->actingAs($clerk)->get($showUrl)
        ->assertInertia(fn (Assert $page) => $page->where('unit.actions', fn ($actions) => ! collect($actions)->pluck('key')->contains('undo-check-in')));

    $this->actingAs($clerk)->postJson(uaUrl($setup, 'undo-check-in-preview'))->assertForbidden();
    $this->actingAs($clerk)->post(uaUrl($setup, 'undo-check-in'), ['reason' => 'x'])->assertForbidden();

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn);
});

/**
 * A unit that arrived yesterday and checked out today through the route (the 100.00 deposit refunded), so its unit
 * folio is Closed with an invoice number and its room is Dirty.
 *
 * @return array{user: User, property: Property, reservation: Reservation, unit: ReservationUnit}
 */
function uaCheckedOutSetup(): array
{
    $setup = uaDepartingSetup();
    test()->actingAs($setup['user'])
        ->post(uaUrl($setup, 'check-out'), ['refund_amount' => '100.00', 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasNoErrors();

    return $setup;
}

test('undo check-out puts the unit back to CheckedIn, reopens the folio with its number and keeps the refund', function () {
    $setup = uaCheckedOutSetup();
    $folio = Folio::query()->where('reservation_unit_id', $setup['unit']->id)->sole();
    $number = $folio->invoice_number;

    $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'undo-check-out-preview'))
        ->assertOk()
        ->assertJsonPath('error', null)
        ->assertJsonPath('reopens.0', $folio->number.' · '.$number);

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'undo-check-out'), ['reason' => 'Guest stays another night'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $unit = $setup['unit']->fresh();
    $log = Activity::query()->where('event', 'reservation.unit_check_out_undone')->sole();

    expect($unit->status)->toBe(UnitStatus::CheckedIn)
        ->and($unit->checked_out_at)->toBeNull()
        ->and($folio->fresh()->status)->toBe(FolioStatus::Open)
        ->and($folio->fresh()->invoice_number)->toBe($number)
        ->and(Payment::query()->whereNotNull('refund_of_id')->count())->toBe(1)
        ->and($log->properties['reason'])->toBe('Guest stays another night')
        ->and($log->properties['reopened_folio_ids'])->toBe([$folio->id])
        ->and($setup['reservation']->fresh()->status)->toBe(ReservationStatus::InHouse);
});

test('undo check-out needs a reason and the night audit not to have run', function () {
    $setup = uaCheckedOutSetup();

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'undo-check-out'), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    $setup['unit']->forceFill(['checked_out_at' => now()->subDay(), 'checked_in_at' => now()->subDays(2)])->save();

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'undo-check-out'), ['reason' => 'Mistake'])
        ->assertSessionHasErrors('form');

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedOut)
        ->and(Folio::query()->where('reservation_unit_id', $setup['unit']->id)->sole()->status)->toBe(FolioStatus::Closed)
        ->and(Activity::query()->where('event', 'reservation.unit_check_out_undone')->count())->toBe(0);
});

test('undo check-out is refused when another unit holds the room tonight', function () {
    $setup = uaCheckedOutSetup();
    $roomId = $setup['unit']->nights()->value('room_id');
    $other = ReservationUnit::factory()->for($setup['reservation'])->withNights()->create(['room_type_id' => $setup['unit']->room_type_id, 'arrival_date' => today()->toDateString(), 'departure_date' => today()->addDay()->toDateString()]);
    $other->nights()->update(['room_id' => $roomId]);

    $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'undo-check-out-preview'))
        ->assertJsonPath('error', 'Another guest holds this room tonight, so the check-out cannot be undone.');

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'undo-check-out'), ['reason' => 'Mistake'])
        ->assertSessionHasErrors('form');

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedOut);
});

test('undo check-out is refused when the folio was settled to the city ledger', function () {
    $setup = uaCheckedOutSetup();
    $folio = Folio::query()->where('reservation_unit_id', $setup['unit']->id)->sole();
    ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'folio_id' => $folio->id]);

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'undo-check-out'), ['reason' => 'Mistake'])
        ->assertSessionHasErrors('form');

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedOut)
        ->and($folio->fresh()->status)->toBe(FolioStatus::Closed);
});

test('a checked-in unit and a stranger cannot undo a check-out, and the menu follows the permission', function () {
    $setup = uaCheckedOutSetup();
    $clerk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create(['property_id' => $setup['property']->id]);
    $showUrl = route('reservations.show', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]);

    $this->actingAs($setup['user'])->get($showUrl)
        ->assertInertia(fn (Assert $page) => $page->where('unit.actions', fn ($actions) => collect($actions)->pluck('key')->contains('undo-check-out')));
    $this->actingAs($clerk)->get($showUrl)
        ->assertInertia(fn (Assert $page) => $page->where('unit.actions', fn ($actions) => ! collect($actions)->pluck('key')->contains('undo-check-out')));
    $this->actingAs($clerk)->post(uaUrl($setup, 'undo-check-out'), ['reason' => 'x'])->assertForbidden();

    $inHouse = uaDepartingSetup();
    $this->actingAs($inHouse['user'])->post(uaUrl($inHouse, 'undo-check-out'), ['reason' => 'x'])->assertSessionHasErrors('form');

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedOut);
});

test('undo check-out through the Action twice does not reopen again', function () {
    $setup = uaCheckedOutSetup();

    $first = app(UndoCheckOut::class)($setup['user'], $setup['unit']->fresh(), 'Mistake');
    $second = app(UndoCheckOut::class)($setup['user'], $setup['unit']->fresh(), 'Mistake');

    expect($first->isRefused())->toBeFalse()
        ->and($second->refusal)->toBe(UnitStayResult::REFUSAL_INVALID_STATUS)
        ->and(Activity::query()->where('event', 'reservation.unit_check_out_undone')->count())->toBe(1);
});

test('a single-unit reservation sends its one live unit on every tab, and a group does not', function () {
    $setup = uaSetup();
    $params = ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id];

    foreach (['reservations.show', 'reservations.guests', 'reservations.folio', 'reservations.activity'] as $route) {
        $this->actingAs($setup['user'])->get(route($route, $params))
            ->assertInertia(fn (Assert $page) => $page
                ->where('singleUnit.id', $setup['unit']->id)
                ->where('hasUnitToCheckIn', true)
                ->where('singleUnit.actions', fn ($actions) => collect($actions)->pluck('key')->contains('check-in')));
    }

    ReservationUnit::factory()->for($setup['reservation'])->create(['room_type_id' => $setup['unit']->room_type_id]);

    $this->actingAs($setup['user'])->get(route('reservations.guests', $params))
        ->assertInertia(fn (Assert $page) => $page->where('singleUnit', null));
});

test('a cancelled unit does not make a group, and the live unit is the single one', function () {
    $setup = uaSetup();
    $cancelled = ReservationUnit::factory()->for($setup['reservation'])->create(['room_type_id' => $setup['unit']->room_type_id, 'status' => UnitStatus::Cancelled]);
    $params = ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id];

    $this->actingAs($setup['user'])->get(route('reservations.show', $params + ['unit' => $cancelled->id]))
        ->assertInertia(fn (Assert $page) => $page->where('unit.id', $cancelled->id)->where('singleUnit.id', $setup['unit']->id));
});

test('the single unit offers Check out once checked in, and Check in all hides with no Confirmed unit', function () {
    $setup = uaDepartingSetup();
    $params = ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id];

    $this->actingAs($setup['user'])->get(route('reservations.guests', $params))
        ->assertInertia(fn (Assert $page) => $page
            ->where('hasUnitToCheckIn', false)
            ->where('singleUnit.actions', fn ($actions) => collect($actions)->pluck('key')->contains('check-out') && ! collect($actions)->pluck('key')->contains('check-in')));
});

test('the single unit carries only the actions the user may use', function () {
    $setup = uaSetup();
    $viewer = User::factory()->for($setup['property'])->withPermissions(['reservations.view'])->create();

    $this->actingAs($viewer)->get(route('reservations.guests', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]))
        ->assertInertia(fn (Assert $page) => $page->where('singleUnit.actions', []));
});

test('during a support session the owner money switch decides a deposit refund at check-out', function () {
    $setup = uaDepartingSetup();
    PropertySetting::query()->where('property_id', $setup['property']->id)->update(['allow_support_money_corrections' => false]);
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('view-as.store', $setup['property']->code), ['user' => $setup['user']->id, 'reason' => 'support'])
        ->assertRedirect();

    $this->postJson(uaUrl($setup, 'check-out-preview'))->assertOk()->assertJsonPath('canRefund', false);

    $this->post(uaUrl($setup, 'check-out'), ['refund_amount' => '100.00', 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasErrors('refund_amount');
    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn)
        ->and(Payment::query()->whereNotNull('refund_of_id')->count())->toBe(0);

});

test('during a support session with the money switch off, a check-out without a refund still saves', function () {
    $setup = uaDepartingSetup(withDeposit: false);
    PropertySetting::query()->where('property_id', $setup['property']->id)->update(['allow_support_money_corrections' => false]);
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('view-as.store', $setup['property']->code), ['user' => $setup['user']->id, 'reason' => 'support'])
        ->assertRedirect();

    $this->post(uaUrl($setup, 'check-out'), ['idempotency_key' => fake()->uuid()])->assertSessionHasNoErrors();
    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedOut);
});

/** A payment of the Advance purpose on the unit folio, taken in cash. */
function uaAdvance(array $setup, string $amount): Payment
{
    $cash = PaymentMethod::query()->where('property_id', $setup['property']->id)->where('type', PaymentMethodType::Cash)->firstOrFail();

    return app(RecordPayment::class)($setup['user'], $setup['reservation'], null, $setup['unit'], new NewPaymentData($cash->id, PaymentPurpose::Advance, $amount, null, fake()->uuid()))->payment;
}

/** @param array<string, mixed> $attributes */
function uaEarlyPolicy(array $setup, array $attributes): void
{
    RoomRevenuePolicy::query()->where('property_id', $setup['property']->id)->where('code', SystemTransactionCode::EarlyDepartureRevenue->value)->update($attributes);
}

test('a prepaid stay checked out early with a free policy refunds the deposit and the advance and closes the folio', function () {
    $setup = uaEarlySetup();
    uaAdvance($setup, '200.00');
    uaEarlyPolicy($setup, ['charge_mode' => 'free', 'amount' => null]);

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'early-check-out'), ['refund_amount' => '300.00', 'expected_fee' => '0.00', 'idempotency_key' => fake()->uuid()])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $unit = $setup['unit']->fresh();

    expect($unit->status)->toBe(UnitStatus::CheckedOut)
        ->and(Payment::query()->whereNotNull('refund_of_id')->where('purpose', PaymentPurpose::SecurityDeposit)->sole()->amount)->toBe('100.00')
        ->and(Payment::query()->whereNotNull('refund_of_id')->where('purpose', PaymentPurpose::Advance)->sole()->amount)->toBe('200.00')
        ->and(Folio::query()->where('reservation_unit_id', $unit->id)->sole()->status)->toBe(FolioStatus::Closed);
});

test('the deposit is refunded before the advance, and the advance gives back the rest of the credit', function () {
    $setup = uaEarlySetup();
    uaAdvance($setup, '200.00');
    uaEarlyPolicy($setup, ['amount' => '50.00']);

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'early-check-out'), ['refund_amount' => '250.00', 'expected_fee' => '50.00', 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasNoErrors();

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedOut)
        ->and(Payment::query()->whereNotNull('refund_of_id')->where('purpose', PaymentPurpose::SecurityDeposit)->sole()->amount)->toBe('100.00')
        ->and(Payment::query()->whereNotNull('refund_of_id')->where('purpose', PaymentPurpose::Advance)->sole()->amount)->toBe('150.00');
});

test('a refund above the credit and the deposit is refused before anything is refunded', function () {
    $setup = uaDepartingSetup();
    uaAdvance($setup, '200.00');

    $this->actingAs($setup['user'])
        ->post(uaUrl($setup, 'check-out'), ['refund_amount' => '350.00', 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasErrors('refund_amount');

    expect(Payment::query()->whereNotNull('refund_of_id')->count())->toBe(0)
        ->and($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn);
});

test('the check-out preview refunds a credit above the deposit, deposit first, as parts', function () {
    $setup = uaDepartingSetup();
    uaAdvance($setup, '200.00');

    $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'check-out-preview'))
        ->assertOk()
        ->assertJsonPath('balance', '-300.00')
        ->assertJsonPath('refundDefault', '300.00')
        ->assertJsonPath('refundable', '300.00')
        ->assertJsonPath('refundParts.0.purpose', PaymentPurpose::SecurityDeposit->label())
        ->assertJsonPath('refundParts.0.amount', '100.00')
        ->assertJsonPath('refundParts.1.purpose', PaymentPurpose::Advance->label())
        ->assertJsonPath('refundParts.1.amount', '200.00')
        ->assertJsonPath('error', null);
});

test('a free early departure fee adds no flat tax, so the preview refund settles the folio', function () {
    $setup = uaEarlySetup();
    uaEarlyPolicy($setup, ['charge_mode' => 'free', 'amount' => null]);
    $code = TransactionCode::query()->where('property_id', $setup['property']->id)->where('system_key', SystemTransactionCode::EarlyDepartureRevenue->value)->sole();
    $code->applicableTaxes()->attach(Tax::factory()->for($setup['property'])->create(['rate_type' => TaxRateType::Flat, 'rate' => '10.00']));

    $preview = $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'check-out-preview'))
        ->assertOk()
        ->assertJsonPath('early.feeTotal', '0.00')
        ->assertJsonPath('refundDefault', '100.00');

    $this->post(uaUrl($setup, 'early-check-out'), ['refund_amount' => $preview->json('refundDefault'), 'expected_fee' => '0.00', 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasNoErrors();

    expect($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedOut)
        ->and(uaEarlyFeeLines($setup['unit']))->toBe(0);
});

test('the check-out preview breaks the balance into charges, paid and the deposit held', function () {
    $setup = uaDepartingSetup();
    uaOwing($setup);
    uaAdvance($setup, '30.00');

    $this->actingAs($setup['user'])->postJson(uaUrl($setup, 'check-out-preview'))
        ->assertOk()
        ->assertJson(['charges' => '300.00', 'paid' => '-30.00', 'depositHeld' => '100.00', 'balance' => '170.00']);
});
