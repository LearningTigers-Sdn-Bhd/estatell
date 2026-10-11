<?php

use App\Actions\Folio\CreateFolio;
use App\Actions\Payments\RecordPayment;
use App\Data\Payments\NewPaymentData;
use App\Enums\DefaultRoleEnum;
use App\Enums\FolioStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionCategory;
use App\Enums\UnitStatus;
use App\Models\ArInvoice;
use App\Models\BusinessDate;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\FolioRoutingRule;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\TransactionCode;
use App\Models\UnitNight;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * A live property with an open business date (2026-10-10), a cash method, the Refund and Room codes, a reservation with
 * an open Master folio and `$count` CheckedIn units that depart today, each with its Unit folio at 0.
 *
 * @return array{user: User, property: Property, reservation: Reservation, cash: PaymentMethod, card: PaymentMethod, master: Folio, units: list<ReservationUnit>, folios: list<Folio>, code: TransactionCode}
 */
function gcSetup(int $count = 3): array
{
    $user = User::factory()->withRole(DefaultRoleEnum::Owner)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);
    $code = TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $property->id]);
    TransactionCode::factory()->system(SystemTransactionCode::Refund)->create(['property_id' => $property->id]);
    $cash = PaymentMethod::factory()->create(['property_id' => $property->id, 'type' => PaymentMethodType::Cash, 'requires_reference' => false, 'position' => 1]);
    $card = PaymentMethod::factory()->create(['property_id' => $property->id, 'type' => PaymentMethodType::Card, 'requires_reference' => true, 'position' => 2]);
    $roomType = RoomType::factory()->for($property)->create();
    $reservation = Reservation::factory()->group()->create(['property_id' => $property->id]);
    $master = Folio::factory()->master()->create(['property_id' => $property->id, 'reservation_id' => $reservation->id]);
    $units = [];
    $folios = [];

    for ($i = 1; $i <= $count; $i++) {
        $room = Room::factory()->for($property)->create(['room_type_id' => $roomType->id, 'number' => (string) (100 + $i)]);
        $unit = ReservationUnit::factory()->for($reservation)->withNights()->checkedIn()->create(['room_type_id' => $roomType->id, 'arrival_date' => '2026-10-09', 'departure_date' => '2026-10-10']);
        $unit->nights()->update(['room_id' => $room->id]);
        $units[] = $unit;
        $folios[] = app(CreateFolio::class)($reservation, $unit);
    }

    return compact('user', 'property', 'reservation', 'cash', 'card', 'master', 'units', 'folios', 'code');
}

function gcCharge(array $setup, Folio $folio, string $amount): FolioLine
{
    return FolioLine::factory()->create([
        'folio_id' => $folio->id,
        'transaction_code_id' => $setup['code']->id,
        'amount' => $amount,
        'unit_amount' => $amount,
        'posting_date' => '2026-10-10',
        'idempotency_key' => 'manual:'.fake()->uuid(),
    ]);
}

function gcDeposit(array $setup, ReservationUnit $unit, string $amount, PaymentPurpose $purpose = PaymentPurpose::SecurityDeposit): Payment
{
    return app(RecordPayment::class)($setup['user'], $setup['reservation'], null, $unit, new NewPaymentData($setup['cash']->id, $purpose, $amount, null, fake()->uuid()))->payment;
}

function gcUrl(array $setup, string $route): string
{
    return route('reservations.check-out.'.$route, ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]);
}

/** @param array<int, array<string, mixed>> $rows */
function gcSave(object $test, array $setup, array $rows, array $extra = [], ?User $as = null)
{
    return $test->actingAs($as ?? $setup['user'])->post(gcUrl($setup, 'store'), ['idempotency_key' => $extra['key'] ?? fake()->uuid(), 'rows' => $rows, ...array_diff_key($extra, ['key' => 1])]);
}

function gcStaff(Property $property, array $permissions): User
{
    $user = User::factory()->for($property)->create();
    $role = Role::query()->create(['property_id' => $property->id, 'name' => 'Only '.implode(',', $permissions)]);
    $role->givePermissionTo($permissions);
    setPermissionsTeamId($property->id);
    $user->assignRole($role);

    return $user;
}

function gcBalance(Folio $folio): string
{
    return bcadd((string) FolioLine::query()->where('folio_id', $folio->id)->sum('amount'), '0', 2);
}

test('ready rooms check out with collect, refund and add to master in one save', function () {
    $s = gcSetup(4);
    gcCharge($s, $s['folios'][1], '45.00');
    gcDeposit($s, $s['units'][2], '100.00');
    gcCharge($s, $s['folios'][3], '80.00');

    gcSave($this, $s, [
        ['unit_id' => $s['units'][0]->id],
        ['unit_id' => $s['units'][1]->id, 'choice' => 'collect', 'payment_method_id' => $s['cash']->id, 'amount' => '45.00'],
        ['unit_id' => $s['units'][2]->id, 'choice' => 'refund', 'amount' => '100.00'],
        ['unit_id' => $s['units'][3]->id, 'choice' => 'move'],
    ])->assertSessionHasNoErrors();

    foreach ($s['units'] as $unit) {
        expect($unit->fresh()->status)->toBe(UnitStatus::CheckedOut);
    }

    expect(Payment::query()->whereNull('refund_of_id')->where('purpose', PaymentPurpose::Settlement)->sole()->amount)->toBe('45.00')
        ->and(Payment::query()->whereNotNull('refund_of_id')->sole()->amount)->toBe('100.00')
        ->and(gcBalance($s['master']))->toBe('80.00')
        ->and(Folio::query()->where('reservation_unit_id', $s['units'][3]->id)->sole()->status)->toBe(FolioStatus::Closed)
        ->and(Activity::query()->where('event', 'reservation.group_checked_out')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'reservation.unit_checked_out')->count())->toBe(4);
});

test('one payment for all moves the balances to the paying room and records one payment', function () {
    $s = gcSetup(3);
    gcCharge($s, $s['folios'][0], '30.00');
    gcCharge($s, $s['folios'][1], '45.00');

    gcSave($this, $s, [
        ['unit_id' => $s['units'][0]->id, 'choice' => 'collect', 'amount' => '30.00'],
        ['unit_id' => $s['units'][1]->id, 'choice' => 'collect', 'amount' => '45.00'],
        ['unit_id' => $s['units'][2]->id],
    ], ['one_payment' => ['payer_unit_id' => $s['units'][0]->id, 'payment_method_id' => $s['cash']->id, 'amount' => '75.00']])
        ->assertSessionHasNoErrors();

    $payment = Payment::query()->sole();

    expect($payment->amount)->toBe('75.00')
        ->and($payment->folio_id)->toBe($s['folios'][0]->id)
        ->and(UnitStatus::CheckedOut)->toBe($s['units'][1]->fresh()->status)
        ->and(gcBalance($s['folios'][1]))->toBe('0.00')
        ->and(Folio::query()->where('status', FolioStatus::Closed)->count())->toBe(3);
});

test('one payment for all is refused when its total changed', function () {
    $s = gcSetup(2);
    gcCharge($s, $s['folios'][1], '45.00');

    gcSave($this, $s, [
        ['unit_id' => $s['units'][0]->id],
        ['unit_id' => $s['units'][1]->id, 'choice' => 'collect', 'amount' => '45.00'],
    ], ['one_payment' => ['payer_unit_id' => $s['units'][0]->id, 'payment_method_id' => $s['cash']->id, 'amount' => '40.00']])
        ->assertSessionHasErrors('units.'.$s['units'][0]->id);

    expect(Payment::query()->count())->toBe(0)
        ->and($s['units'][1]->fresh()->status)->toBe(UnitStatus::CheckedIn)
        ->and(gcBalance($s['folios'][1]))->toBe('45.00');
});

test('a room whose folio holds a deposit refunds it with the move, and a user who cannot refund is refused', function () {
    $s = gcSetup(2);
    gcCharge($s, $s['folios'][0], '95.00');
    gcDeposit($s, $s['units'][0], '50.00');

    $clerk = gcStaff($s['property'], ['reservations.view', 'front-desk.check-out', 'folio.transfer']);
    gcSave($this, $s, [['unit_id' => $s['units'][0]->id, 'choice' => 'move']], as: $clerk)
        ->assertSessionHasErrors('units.'.$s['units'][0]->id);

    expect($s['units'][0]->fresh()->status)->toBe(UnitStatus::CheckedIn)
        ->and(gcBalance($s['master']))->toBe('0.00');

    gcSave($this, $s, [['unit_id' => $s['units'][0]->id, 'choice' => 'move']])->assertSessionHasNoErrors();

    expect(gcBalance($s['master']))->toBe('95.00')
        ->and(Payment::query()->whereNotNull('refund_of_id')->sole()->amount)->toBe('50.00')
        ->and($s['units'][0]->fresh()->status)->toBe(UnitStatus::CheckedOut)
        ->and(gcBalance($s['folios'][0]))->toBe('0.00');
});

test('a checked out room with a late charge is settled without a second check-out and its folio closes', function () {
    $s = gcSetup(2);
    $out = ReservationUnit::factory()->for($s['reservation'])->checkedOut()->create(['room_type_id' => $s['units'][0]->room_type_id, 'arrival_date' => '2026-10-08', 'departure_date' => '2026-10-09']);
    $folio = app(CreateFolio::class)($s['reservation'], $out);
    gcCharge($s, $folio, '30.00');

    gcSave($this, $s, [
        ['unit_id' => $out->id, 'choice' => 'collect', 'payment_method_id' => $s['cash']->id, 'amount' => '30.00'],
    ])->assertSessionHasNoErrors();

    expect(Payment::query()->sole()->amount)->toBe('30.00')
        ->and($folio->fresh()->status)->toBe(FolioStatus::Closed)
        ->and(Activity::query()->where('event', 'reservation.unit_checked_out')->count())->toBe(0)
        ->and($s['units'][0]->fresh()->status)->toBe(UnitStatus::CheckedIn);
});

test('one refused row saves nothing, also when the refusal comes from inside the check-out', function (array $bad) {
    $s = gcSetup(3);
    gcCharge($s, $s['folios'][0], '45.00');
    gcCharge($s, $s['folios'][1], '60.00');

    gcSave($this, $s, [
        ['unit_id' => $s['units'][0]->id, 'choice' => 'collect', 'payment_method_id' => $s['cash']->id, 'amount' => '45.00'],
        ['unit_id' => $s['units'][1]->id, ...($bad['row'] ?? [])],
        ['unit_id' => $s['units'][2]->id],
    ])->assertSessionHasErrors('units.'.$s['units'][1]->id);

    foreach ($s['units'] as $unit) {
        expect($unit->fresh()->status)->toBe(UnitStatus::CheckedIn);
    }

    expect(Payment::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0)
        ->and(gcBalance($s['folios'][0]))->toBe('45.00');
})->with([
    'balance not settled' => [['row' => ['choice' => 'collect', 'payment_method_id' => 0, 'amount' => '10.00']]],
    'nothing chosen for a balance' => [[]],
]);

test('a card payment without a reference refuses the whole save', function () {
    $s = gcSetup(2);
    gcCharge($s, $s['folios'][0], '45.00');
    gcCharge($s, $s['folios'][1], '60.00');

    gcSave($this, $s, [
        ['unit_id' => $s['units'][0]->id, 'choice' => 'collect', 'payment_method_id' => $s['cash']->id, 'amount' => '45.00'],
        ['unit_id' => $s['units'][1]->id, 'choice' => 'collect', 'payment_method_id' => $s['card']->id, 'amount' => '60.00'],
    ])->assertSessionHasErrors(['units.'.$s['units'][1]->id => 'Room 102: This payment method needs a reference.']);

    expect(Payment::query()->count())->toBe(0)->and($s['units'][0]->fresh()->status)->toBe(UnitStatus::CheckedIn);
});

test('a double submit records each payment once', function () {
    $s = gcSetup(3);
    gcCharge($s, $s['folios'][0], '45.00');
    gcCharge($s, $s['folios'][1], '80.00');
    gcDeposit($s, $s['units'][2], '100.00');
    $rows = [
        ['unit_id' => $s['units'][0]->id, 'choice' => 'collect', 'payment_method_id' => $s['cash']->id, 'amount' => '45.00'],
        ['unit_id' => $s['units'][1]->id, 'choice' => 'move'],
        ['unit_id' => $s['units'][2]->id, 'choice' => 'refund', 'amount' => '100.00'],
    ];
    $key = fake()->uuid();

    gcSave($this, $s, $rows, ['key' => $key])->assertSessionHasNoErrors();
    gcSave($this, $s, $rows, ['key' => $key])->assertSessionHasNoErrors();

    expect(Payment::query()->whereNull('refund_of_id')->where('purpose', PaymentPurpose::Settlement)->count())->toBe(1)
        ->and(Payment::query()->whereNotNull('refund_of_id')->count())->toBe(1)
        ->and(gcBalance($s['master']))->toBe('80.00')
        ->and(Activity::query()->where('event', 'reservation.group_checked_out')->count())->toBe(1);
});

test('a double submit of one payment for all records one payment', function () {
    $s = gcSetup(2);
    gcCharge($s, $s['folios'][1], '45.00');
    $rows = [['unit_id' => $s['units'][0]->id], ['unit_id' => $s['units'][1]->id, 'choice' => 'collect', 'amount' => '45.00']];
    $extra = ['key' => fake()->uuid(), 'one_payment' => ['payer_unit_id' => $s['units'][0]->id, 'payment_method_id' => $s['cash']->id, 'amount' => '45.00']];

    gcSave($this, $s, $rows, $extra)->assertSessionHasNoErrors();
    gcSave($this, $s, $rows, $extra)->assertSessionHasNoErrors();

    expect(Payment::query()->count())->toBe(1);
});

/** A direct bill company on the Master, a DIRECT_BILL code, and the Master holding `$balance`. */
function gcCompany(array $setup, string $balance = '80.00'): void
{
    TransactionCode::factory()->system(SystemTransactionCode::DirectBillPayment)->create(['property_id' => $setup['property']->id]);
    $company = Company::factory()->for($setup['property'])->create();
    $setup['master']->forceFill(['payer_company_id' => $company->id])->save();
    gcCharge($setup, $setup['master'], $balance);
}

test('settle runs after every room is out and makes the invoice', function () {
    $s = gcSetup(2);
    gcCompany($s);

    gcSave($this, $s, [['unit_id' => $s['units'][0]->id], ['unit_id' => $s['units'][1]->id]], ['settle_master' => true])->assertSessionHasNoErrors();

    expect(ArInvoice::query()->count())->toBe(1)
        ->and($s['master']->fresh()->status)->toBe(FolioStatus::Closed)
        ->and($s['units'][1]->fresh()->status)->toBe(UnitStatus::CheckedOut);
});

test('settle is refused while a room is still in house and saves nothing', function () {
    $s = gcSetup(3);
    gcCompany($s);

    gcSave($this, $s, [['unit_id' => $s['units'][0]->id], ['unit_id' => $s['units'][1]->id]], ['settle_master' => true])
        ->assertSessionHasErrors('form');

    expect(ArInvoice::query()->count())->toBe(0)
        ->and($s['units'][0]->fresh()->status)->toBe(UnitStatus::CheckedIn)
        ->and($s['master']->fresh()->status)->toBe(FolioStatus::Open);
});

test('settle is refused while a live unit routes scheduled charges to the Master', function () {
    $s = gcSetup(2);
    gcCompany($s);
    $later = ReservationUnit::factory()->for($s['reservation'])->withNights()->create(['room_type_id' => $s['units'][0]->room_type_id, 'arrival_date' => '2026-10-12', 'departure_date' => '2026-10-13']);
    FolioRoutingRule::factory()->forUnit($later)->forCategory(TransactionCategory::Room)->toFolio($s['master'])->create(['reservation_id' => $s['reservation']->id]);

    gcSave($this, $s, [['unit_id' => $s['units'][0]->id], ['unit_id' => $s['units'][1]->id]], ['settle_master' => true])
        ->assertSessionHasErrors('form');

    expect(ArInvoice::query()->count())->toBe(0)->and($s['units'][0]->fresh()->status)->toBe(UnitStatus::CheckedIn);
});

test('disabled rows are refused and never touched', function (string $case) {
    $s = gcSetup(2);
    $unit = match ($case) {
        'departs later' => tap($s['units'][1])->update(['departure_date' => '2026-10-12']),
        'not arrived' => ReservationUnit::factory()->for($s['reservation'])->create(['room_type_id' => $s['units'][0]->room_type_id]),
        'charged by another room' => $s['units'][0],
    };

    if ($case === 'charged by another room') {
        $s['units'][1]->update(['departure_date' => '2026-10-12']);
        $s['units'][1]->nights()->get()->each->delete();
        UnitNight::factory()->create(['reservation_unit_id' => $s['units'][1]->id, 'rate_plan_id' => $s['units'][1]->rate_plan_id, 'date' => '2026-10-10']);
        FolioRoutingRule::factory()->forUnit($s['units'][1])->forCategory(TransactionCategory::Room)->toFolio($s['folios'][0])->create(['reservation_id' => $s['reservation']->id]);
    }

    gcSave($this, $s, [['unit_id' => $unit->id]])->assertSessionHasErrors('units.'.$unit->id);

    expect($unit->fresh()->status)->toBe($case === 'not arrived' ? UnitStatus::Confirmed : UnitStatus::CheckedIn)
        ->and(Activity::query()->count())->toBe(0);
})->with(['departs later', 'not arrived', 'charged by another room']);

test('each choice needs its own permission', function (string $choice, array $row, array $permissions, array $extra) {
    $s = gcSetup(2);
    gcCharge($s, $s['folios'][0], '45.00');
    $clerk = gcStaff($s['property'], ['reservations.view', 'front-desk.check-out', ...$permissions]);

    gcSave($this, $s, [['unit_id' => $s['units'][0]->id, ...$row]], $extra, $clerk)->assertSessionHasErrors();

    expect($s['units'][0]->fresh()->status)->toBe(UnitStatus::CheckedIn);
})->with(fn () => [
    'collect' => ['collect', ['choice' => 'collect', 'payment_method_id' => 1, 'amount' => '45.00'], [], []],
    'refund' => ['refund', ['choice' => 'refund', 'amount' => '10.00'], [], []],
    'move' => ['move', ['choice' => 'move'], [], []],
    'settle' => ['settle', [], [], ['settle_master' => true]],
]);

test('the route needs front-desk.check-out and another property or reservation is a 404', function () {
    $s = gcSetup(1);
    $viewer = gcStaff($s['property'], ['reservations.view']);

    gcSave($this, $s, [['unit_id' => $s['units'][0]->id]], as: $viewer)->assertForbidden();

    $other = gcSetup(1);
    $this->actingAs($other['user'])->post(gcUrl($s, 'store'), ['idempotency_key' => fake()->uuid(), 'rows' => [['unit_id' => $s['units'][0]->id]]])->assertNotFound();
    $this->actingAs($other['user'])->postJson(gcUrl($s, 'preview'))->assertNotFound();
    $this->actingAs($s['user'])->post(gcUrl($s, 'store'), ['idempotency_key' => fake()->uuid(), 'rows' => [['unit_id' => $other['units'][0]->id]]])->assertNotFound();
    $this->actingAs($s['user'])->postJson(gcUrl($s, 'preview'), ['rows' => [['unit_id' => $other['units'][0]->id]]])->assertNotFound();
});

test('the preview groups the rooms, prefills the choices and writes nothing', function () {
    $s = gcSetup(3);
    gcCharge($s, $s['folios'][1], '45.00');
    gcDeposit($s, $s['units'][2], '100.00');
    $later = ReservationUnit::factory()->for($s['reservation'])->checkedIn()->create(['room_type_id' => $s['units'][0]->room_type_id, 'arrival_date' => '2026-10-09', 'departure_date' => '2026-10-15']);
    app(CreateFolio::class)($s['reservation'], $later);
    $before = Activity::query()->count();

    $response = $this->actingAs($s['user'])->postJson(gcUrl($s, 'preview'))->assertOk();
    $rows = collect($response->json('rows'))->keyBy('unitId');

    expect($rows[$s['units'][0]->id]['defaultChoice'])->toBeNull()
        ->and($rows[$s['units'][1]->id]['defaultChoice'])->toBe('collect')
        ->and($rows[$s['units'][1]->id]['collectAmount'])->toBe('45.00')
        ->and($rows[$s['units'][1]->id]['choices'])->toBe(['collect', 'move'])
        ->and($rows[$s['units'][2]->id]['defaultChoice'])->toBe('refund')
        ->and($rows[$s['units'][2]->id]['refundAmount'])->toBe('100.00')
        ->and($rows[$later->id]['selectable'])->toBeFalse()
        ->and($rows[$later->id]['ticked'])->toBeFalse()
        ->and($response->json('master.balance'))->toBe('0.00')
        ->and($response->json('summary.collect'))->toBe('45.00')
        ->and($response->json('summary.refund'))->toBe('100.00')
        ->and($response->json('summary.roomsOut'))->toBe(3)
        ->and(Activity::query()->count())->toBe($before)
        ->and($s['units'][1]->fresh()->status)->toBe(UnitStatus::CheckedIn);
});
