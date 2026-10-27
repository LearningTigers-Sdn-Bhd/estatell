<?php

use App\Actions\Folio\CreateFolio;
use App\Actions\NightAudit\RunNightAudit;
use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\RefundPayment;
use App\Data\Payments\NewPaymentData;
use App\Enums\AuditTrigger;
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
use App\Models\Guest;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\TransactionCode;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\Payments\PaymentReceipt;
use App\Services\Property\PrintProfile;
use Brick\Math\BigDecimal;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
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

function gcBalance(Folio $folio): string
{
    return (string) BigDecimal::of((string) FolioLine::query()->where('folio_id', $folio->id)->sum('amount'))->toScale(2);
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

    $clerk = User::factory()->for($s['property'])->withPermissions(['reservations.view', 'front-desk.check-out', 'folio.transfer'])->create();
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
    $clerk = User::factory()->for($s['property'])->withPermissions(['reservations.view', 'front-desk.check-out', ...$permissions])->create();

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
    $viewer = User::factory()->for($s['property'])->withPermissions(['reservations.view'])->create();

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

test('the page renders the prefilled preview and the cancel link', function () {
    $s = gcSetup(2);
    gcCharge($s, $s['folios'][1], '45.00');
    $url = route('reservations.check-out', ['property' => $s['property']->code, 'reservation' => $s['reservation']->id]);

    $this->actingAs($s['user'])->get($url)->assertInertia(fn (Assert $page) => $page
        ->component('reservations/check-out')
        ->where('checkOut.reservationId', $s['reservation']->id)
        ->where('checkOut.reference', $s['reservation']->reference)
        ->where('checkOut.cancelUrl', route('reservations.show', ['property' => $s['property']->code, 'reservation' => $s['reservation']->id]))
        ->has('checkOut.preview.rows', 2)
        ->where('checkOut.preview.summary.collect', '45.00')
        ->where('checkOut.preview.master.folioId', $s['master']->id)
        ->where('checkOut.preview.methods.0.id', $s['cash']->id));
});

test('the page needs front-desk.check-out and another property or reservation is a 404', function () {
    $s = gcSetup(1);
    $viewer = User::factory()->for($s['property'])->withPermissions(['reservations.view'])->create();
    $url = route('reservations.check-out', ['property' => $s['property']->code, 'reservation' => $s['reservation']->id]);

    $this->actingAs($viewer)->get($url)->assertForbidden();

    $other = gcSetup(1);
    $this->actingAs($other['user'])->get($url)->assertNotFound();
    $this->actingAs($s['user'])->get(route('reservations.check-out', ['property' => $s['property']->code, 'reservation' => $other['reservation']->id]))->assertNotFound();
});

test('a saved check-out flashes the totals for the success state', function () {
    $s = gcSetup(2);
    gcCharge($s, $s['folios'][1], '45.00');

    gcSave($this, $s, [
        ['unit_id' => $s['units'][0]->id],
        ['unit_id' => $s['units'][1]->id, 'choice' => 'collect', 'payment_method_id' => $s['cash']->id, 'amount' => '45.00'],
    ])->assertRedirect(route('reservations.check-out', ['property' => $s['property']->code, 'reservation' => $s['reservation']->id]))
        ->assertInertiaFlash('groupCheckOut.checkedOut', 2)
        ->assertInertiaFlash('groupCheckOut.collected', '45.00')
        ->assertInertiaFlash('groupCheckOut.paymentIds', [Payment::query()->sole()->id])
        ->assertInertiaFlash('groupCheckOut.canSettleMaster', false)
        ->assertInertiaFlash('groupCheckOut.masterUrl', route('reservations.folio', ['property' => $s['property']->code, 'reservation' => $s['reservation']->id, 'folio' => $s['master']->id]));
});

test('the success state offers the city ledger settle while the Master is open, and the invoice when it was settled', function () {
    $s = gcSetup(2);
    gcCompany($s);

    gcSave($this, $s, [['unit_id' => $s['units'][0]->id], ['unit_id' => $s['units'][1]->id]])
        ->assertInertiaFlash('groupCheckOut.canSettleMaster', true)
        ->assertInertiaFlash('groupCheckOut.invoiceNumber', null);

    $t = gcSetup(2);
    gcCompany($t);

    gcSave($this, $t, [['unit_id' => $t['units'][0]->id], ['unit_id' => $t['units'][1]->id]], ['settle_master' => true])
        ->assertInertiaFlash('groupCheckOut.canSettleMaster', false)
        ->assertInertiaFlash('groupCheckOut.invoiceNumber', ArInvoice::query()->sole()->number)
        ->assertInertiaFlash('groupCheckOut.masterUrl', null);
});

test('the preview puts each room in its section and names the rooms with nothing to settle instead of listing them', function () {
    $s = gcSetup(2);
    gcCharge($s, $s['folios'][1], '45.00');
    $outEmpty = ReservationUnit::factory()->for($s['reservation'])->checkedOut()->create(['room_type_id' => $s['units'][0]->room_type_id, 'arrival_date' => '2026-10-08', 'departure_date' => '2026-10-09']);
    app(CreateFolio::class)($s['reservation'], $outEmpty);
    $outOwing = ReservationUnit::factory()->for($s['reservation'])->checkedOut()->create(['room_type_id' => $s['units'][0]->room_type_id, 'arrival_date' => '2026-10-08', 'departure_date' => '2026-10-09']);
    gcCharge($s, app(CreateFolio::class)($s['reservation'], $outOwing), '30.00');
    $cancelled = ReservationUnit::factory()->for($s['reservation'])->create(['room_type_id' => $s['units'][0]->room_type_id, 'status' => UnitStatus::Cancelled]);
    app(CreateFolio::class)($s['reservation'], $cancelled);
    $later = ReservationUnit::factory()->for($s['reservation'])->checkedIn()->create(['room_type_id' => $s['units'][0]->room_type_id, 'arrival_date' => '2026-10-09', 'departure_date' => '2026-10-15']);
    app(CreateFolio::class)($s['reservation'], $later);
    $notArrived = ReservationUnit::factory()->for($s['reservation'])->create(['room_type_id' => $s['units'][0]->room_type_id]);

    $response = $this->actingAs($s['user'])->postJson(gcUrl($s, 'preview'))->assertOk();
    $rows = collect($response->json('rows'))->keyBy('unitId');

    expect($rows->keys()->all())->toEqualCanonicalizing([$s['units'][0]->id, $s['units'][1]->id, $outOwing->id, $later->id, $notArrived->id])
        ->and($rows[$s['units'][0]->id]['group'])->toBe('ready')
        ->and($rows[$outOwing->id]['group'])->toBe('money')
        ->and($rows[$outOwing->id]['ticked'])->toBeTrue()
        ->and($rows[$later->id]['group'])->toBe('later')
        ->and($rows[$later->id]['unit']['id'])->toBe($later->id)
        ->and($rows[$later->id]['unit']['departure'])->toBe('2026-10-15')
        ->and($rows[$notArrived->id]['group'])->toBe('blocked')
        ->and($rows[$notArrived->id]['unit'])->toBeNull()
        ->and($rows[$s['units'][0]->id]['unit'])->toBeNull()
        ->and($response->json('checkedOutNothing'))->toHaveCount(1)
        ->and($response->json('cancelledNothing'))->toHaveCount(1)
        ->and($response->json('summary.roomsOut'))->toBe(2)
        ->and($response->json('summary.roomsSettled'))->toBe(1);
});

test('Move charges is offered only to a user who has folio.transfer', function () {
    $s = gcSetup(2);
    $s['units'][1]->update(['departure_date' => '2026-10-12']);
    $s['units'][1]->nights()->get()->each->delete();
    UnitNight::factory()->create(['reservation_unit_id' => $s['units'][1]->id, 'rate_plan_id' => $s['units'][1]->rate_plan_id, 'date' => '2026-10-10']);
    FolioRoutingRule::factory()->forUnit($s['units'][1])->forCategory(TransactionCategory::Room)->toFolio($s['folios'][0])->create(['reservation_id' => $s['reservation']->id]);
    $clerk = User::factory()->for($s['property'])->withPermissions(['reservations.view', 'front-desk.check-out'])->create();

    $owner = collect($this->actingAs($s['user'])->postJson(gcUrl($s, 'preview'))->json('rows'))->keyBy('unitId');
    $limited = collect($this->actingAs($clerk)->postJson(gcUrl($s, 'preview'))->json('rows'))->keyBy('unitId');

    expect($owner[$s['units'][0]->id]['group'])->toBe('blocked')
        ->and($owner[$s['units'][0]->id]['incoming'])->not->toBeEmpty()
        ->and($owner[$s['units'][0]->id]['canMoveCharges'])->toBeTrue()
        ->and($limited[$s['units'][0]->id]['canMoveCharges'])->toBeFalse()
        ->and($owner[$s['units'][1]->id]['group'])->toBe('later')
        ->and($owner[$s['units'][1]->id]['canMoveCharges'])->toBeFalse();
});

test('the group bill is named after the payer company, else the group name, and the page sends what Check out early needs', function () {
    $s = gcSetup(2);
    $s['reservation']->update(['group_name' => 'Acme Annual Retreat']);
    $url = route('reservations.check-out', ['property' => $s['property']->code, 'reservation' => $s['reservation']->id]);

    $this->actingAs($s['user'])->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('checkOut.preview.master.name', 'Acme Annual Retreat')
        ->where('canWaiveFees', true)
        ->missing('paymentOptions'));

    gcCompany($s);
    $company = Company::query()->where('property_id', $s['property']->id)->sole();

    $this->actingAs($s['user'])->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('checkOut.preview.master.name', $company->name)
        ->where('checkOut.preview.master.canSettle', true));

    $clerk = User::factory()->for($s['property'])->withPermissions(['reservations.view', 'front-desk.check-out'])->create();

    $this->actingAs($clerk)->get($url)->assertInertia(fn (Assert $page) => $page->where('canWaiveFees', false));
});

test('a Check out early save, done or refused, goes back to the group page', function () {
    $s = gcSetup(2);
    $s['units'][1]->update(['departure_date' => '2026-10-12']);
    $page = route('reservations.check-out', ['property' => $s['property']->code, 'reservation' => $s['reservation']->id]);

    // The property has no fee policy here, so the save is refused: it still returns to the page it came from.
    $this->actingAs($s['user'])->from($page)->post(route('reservations.units.early-check-out', ['property' => $s['property']->code, 'reservation' => $s['reservation']->id, 'unit' => $s['units'][1]->id]), [
        'idempotency_key' => fake()->uuid(),
        'expected_fee' => '0.00',
    ])->assertRedirect($page);
});

test('Print receipts is one PDF with every receipt of the save, in id order', function () {
    $s = gcSetup(2);
    gcCharge($s, $s['folios'][0], '45.00');
    gcCharge($s, $s['folios'][1], '30.00');

    $response = gcSave($this, $s, [
        ['unit_id' => $s['units'][0]->id, 'choice' => 'collect', 'payment_method_id' => $s['cash']->id, 'amount' => '45.00'],
        ['unit_id' => $s['units'][1]->id, 'choice' => 'collect', 'payment_method_id' => $s['cash']->id, 'amount' => '30.00'],
    ]);
    $payments = Payment::query()->orderBy('id')->get();
    $url = gcUrl($s, 'receipts').'?'.http_build_query(['payments' => [$payments[1]->id, $payments[0]->id]]);

    $pdf = $this->actingAs($s['user'])->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $html = view('pdf.receipts', ['receipts' => $payments->map(fn (Payment $payment) => app(PaymentReceipt::class)->forPayment($payment))->all(), 'print' => app(PrintProfile::class)->for($s['property'])])->render();

    expect($pdf->headers->get('Content-Disposition'))->toStartWith('inline;')->toContain('receipts-'.$s['reservation']->reference.'.pdf')
        ->and($pdf->getContent())->toStartWith('%PDF-')
        ->and($payments)->toHaveCount(2)
        ->and(substr_count($html, 'page-break-before'))->toBe(1)
        ->and(strpos($html, $payments[0]->receipt_number))->toBeLessThan(strpos($html, $payments[1]->receipt_number));
});

test('Print receipts is a 404 for a payment of another reservation or property or a refund, and needs payment.record', function () {
    $s = gcSetup(1);
    $deposit = gcDeposit($s, $s['units'][0], '20.00');
    $refund = app(RefundPayment::class)($s['user'], $deposit, '10.00', null, fake()->uuid())->payment;
    gcCharge($s, $s['folios'][0], '45.00');
    gcSave($this, $s, [['unit_id' => $s['units'][0]->id, 'choice' => 'collect', 'payment_method_id' => $s['cash']->id, 'amount' => '45.00']]);
    $payment = Payment::query()->whereNull('refund_of_id')->latest('id')->first();
    $query = fn (array $ids): string => gcUrl($s, 'receipts').'?'.http_build_query(['payments' => $ids]);

    $otherReservation = Reservation::factory()->create(['property_id' => $s['property']->id]);
    $foreign = Payment::factory()->create(['folio_id' => Folio::factory()->master()->create(['property_id' => $s['property']->id, 'reservation_id' => $otherReservation->id])->id]);

    $this->actingAs($s['user'])->get($query([$payment->id, $refund->id]))->assertNotFound();
    $this->actingAs($s['user'])->get($query([$payment->id, $foreign->id]))->assertNotFound();
    $this->actingAs($s['user'])->get($query([$payment->id]))->assertOk();
    $this->actingAs($s['user'])->get(gcUrl($s, 'receipts'))->assertInvalid('payments');
    $this->actingAs(User::factory()->for($s['property'])->withPermissions(['front-desk.check-out'])->create())->get($query([$payment->id]))->assertForbidden();

    $other = gcSetup(1);
    $this->actingAs($other['user'])->get($query([$payment->id]))->assertNotFound();
});

test('the page tells whether the user may print receipts, and a save without payments has no payment ids', function () {
    $s = gcSetup(1);
    $url = route('reservations.check-out', ['property' => $s['property']->code, 'reservation' => $s['reservation']->id]);

    $this->actingAs($s['user'])->get($url)->assertInertia(fn (Assert $page) => $page->where('canPrintReceipts', true));
    $this->actingAs(User::factory()->for($s['property'])->withPermissions(['front-desk.check-out', 'reservations.view'])->create())->get($url)->assertInertia(fn (Assert $page) => $page->where('canPrintReceipts', false));

    gcSave($this, $s, [['unit_id' => $s['units'][0]->id]])->assertInertiaFlash('groupCheckOut.paymentIds', []);
});

test('a room that is out shows the day it checked out, and a cancelled room none', function () {
    $s = gcSetup(1);
    $out = ReservationUnit::factory()->for($s['reservation'])->checkedOut()->create(['room_type_id' => $s['units'][0]->room_type_id, 'arrival_date' => '2026-10-08', 'departure_date' => '2026-10-09', 'checked_out_at' => '2026-10-09 04:00:00']);
    gcCharge($s, app(CreateFolio::class)($s['reservation'], $out), '30.00');
    $cancelled = ReservationUnit::factory()->for($s['reservation'])->create(['room_type_id' => $s['units'][0]->room_type_id, 'status' => UnitStatus::Cancelled]);
    gcCharge($s, app(CreateFolio::class)($s['reservation'], $cancelled), '10.00');

    $rows = collect($this->actingAs($s['user'])->postJson(gcUrl($s, 'preview'))->assertOk()->json('rows'))->keyBy('unitId');

    expect($rows[$out->id]['checkedOutOn'])->toBe('2026-10-09')
        ->and($rows[$cancelled->id]['checkedOutOn'])->toBeNull()
        ->and($rows[$s['units'][0]->id]['checkedOutOn'])->toBeNull();
});

test('the page redirects to the reservation with a toast when no room is left to check out', function () {
    $s = gcSetup(2);
    collect($s['units'])->each(fn ($unit) => $unit->forceFill(['status' => UnitStatus::CheckedOut, 'checked_out_at' => now()])->save());
    $url = route('reservations.check-out', ['property' => $s['property']->code, 'reservation' => $s['reservation']->id]);

    $this->actingAs($s['user'])->get($url)
        ->assertRedirect(route('reservations.show', ['property' => $s['property']->code, 'reservation' => $s['reservation']->id]))
        ->assertInertiaFlash('toast.type', 'info');
});

test('the page still opens right after a save that checked the last room out', function () {
    $s = gcSetup(1);
    $url = route('reservations.check-out', ['property' => $s['property']->code, 'reservation' => $s['reservation']->id]);

    $this->followingRedirects();
    gcSave($this, $s, [['unit_id' => $s['units'][0]->id]])
        ->assertInertia(fn (Assert $page) => $page->component('reservations/check-out'));
});

test('a room that arrived today offers day use instead of early check-out', function () {
    $s = gcSetup(1);
    $today = ReservationUnit::factory()->for($s['reservation'])->checkedIn()->create(['room_type_id' => $s['units'][0]->room_type_id, 'arrival_date' => '2026-10-10', 'departure_date' => '2026-10-15']);
    app(CreateFolio::class)($s['reservation'], $today);
    $yesterday = ReservationUnit::factory()->for($s['reservation'])->checkedIn()->create(['room_type_id' => $s['units'][0]->room_type_id, 'arrival_date' => '2026-10-09', 'departure_date' => '2026-10-15']);
    app(CreateFolio::class)($s['reservation'], $yesterday);

    $rows = collect($this->actingAs($s['user'])->postJson(gcUrl($s, 'preview'))->assertOk()->json('rows'))->keyBy('unitId');

    expect($rows[$today->id]['group'])->toBe('later')
        ->and($rows[$today->id]['unit']['id'])->toBe($today->id)
        ->and($rows[$today->id]['note'])->toBe('Arrived today.')
        ->and($rows[$today->id]['selectable'])->toBeFalse()
        ->and($rows[$today->id]['checkOutAction'])->toMatchArray(['key' => 'check-out-as-day-use', 'label' => 'Check out as day use…', 'disabled' => false])
        ->and($rows[$yesterday->id]['unit']['id'])->toBe($yesterday->id)
        ->and($rows[$yesterday->id]['checkOutAction']['key'])->toBe('check-out')
        ->and($rows[$yesterday->id]['note'])->toBe('Departs 15 Oct');
});

/** The audit has not run: the business date is yesterday (9 Oct) and the calendar date is today (10 Oct). */
function gcPending(): void
{
    BusinessDate::query()->update(['date' => '2026-10-09']);
}

function gcRow(object $test, array $setup, ReservationUnit $unit): array
{
    return collect($test->actingAs($setup['user'])->postJson(gcUrl($setup, 'preview'))->assertOk()->json('rows'))->keyBy('unitId')[$unit->id];
}

test('a room that departs on the calendar date is ready while the audit is pending, with its night in the balance', function () {
    $s = gcSetup(1);
    gcPending();

    $row = gcRow($this, $s, $s['units'][0]);

    $this->actingAs($s['user'])->postJson(gcUrl($s, 'preview'))
        ->assertJsonPath('auditPending.businessDate', '9 Oct')
        ->assertJsonPath('auditPending.today', '10 Oct')
        ->assertJsonPath('auditPending.running', false);

    expect($row['group'])->toBe('ready')
        ->and($row['ticked'])->toBeTrue()
        ->and($row['pendingLines'])->not->toBeEmpty()
        ->and((float) $row['pendingTotal'])->toBeGreaterThan(0.0)
        ->and($row['balance'])->toBe($row['pendingTotal'])
        ->and($row['collectAmount'])->toBe($row['pendingTotal'])
        ->and($row['choices'])->toContain('collect');
});

test('checking a room out while the audit is pending posts its night once and the audit replays it', function () {
    $s = gcSetup(1);
    gcPending();
    $row = gcRow($this, $s, $s['units'][0]);
    $night = $s['units'][0]->nights()->sole();

    gcSave($this, $s, [['unit_id' => $s['units'][0]->id, 'choice' => 'collect', 'payment_method_id' => $s['cash']->id, 'amount' => $row['collectAmount']]])->assertSessionHasNoErrors();

    $lines = FolioLine::query()->count();

    expect($s['units'][0]->fresh()->status)->toBe(UnitStatus::CheckedOut)
        ->and(FolioLine::query()->where('idempotency_key', 'night:'.$night->id)->count())->toBe(1);

    $audit = app(RunNightAudit::class)($s['property'], AuditTrigger::Manual, force: true, forceReason: 'Test');

    expect($audit->isCompleted())->toBeTrue()
        ->and(FolioLine::query()->count())->toBe($lines);
});

test('add to master moves the night that was posted first', function () {
    $s = gcSetup(1);
    gcPending();
    $row = gcRow($this, $s, $s['units'][0]);

    expect($row['choices'])->toContain('move');

    gcSave($this, $s, [['unit_id' => $s['units'][0]->id, 'choice' => 'move']])->assertSessionHasNoErrors();

    expect($s['units'][0]->fresh()->status)->toBe(UnitStatus::CheckedOut)
        ->and(gcBalance($s['master']))->toBe($row['pendingTotal'])
        ->and(gcBalance($s['folios'][0]))->toBe('0.00');
});

test('a room that departs later is no longer blocked while the audit is pending', function () {
    $s = gcSetup(1);
    gcPending();
    $unit = ReservationUnit::factory()->for($s['reservation'])->checkedIn()->create(['room_type_id' => $s['units'][0]->room_type_id, 'arrival_date' => '2026-10-08', 'departure_date' => '2026-10-15']);
    app(CreateFolio::class)($s['reservation'], $unit);

    $row = gcRow($this, $s, $unit);

    expect($row['group'])->toBe('later')
        ->and($row['unit'])->not->toBeNull()
        ->and($row['note'])->toBe('Departs 15 Oct');
});

test('a guest due to leave yesterday is ready with a note and checks out with no extra night', function () {
    $s = gcSetup(1);
    gcPending();
    $s['units'][0]->forceFill(['arrival_date' => '2026-10-08', 'departure_date' => '2026-10-09'])->save();
    $s['units'][0]->nights()->whereDate('date', '2026-10-09')->delete();

    $row = gcRow($this, $s, $s['units'][0]);

    expect($row['group'])->toBe('ready')
        ->and($row['note'])->toBe('Due to leave yesterday (9 Oct). If they stayed last night, check this room out on its own.')
        ->and($row['pendingLines'])->toBe([]);

    gcSave($this, $s, [['unit_id' => $s['units'][0]->id]])->assertSessionHasNoErrors();

    expect($s['units'][0]->fresh()->status)->toBe(UnitStatus::CheckedOut)
        ->and(FolioLine::query()->where('idempotency_key', 'like', 'night:%')->count())->toBe(0);
});

test('a room that departs later while the night audit is overdue says why it cannot leave early', function () {
    $s = gcSetup(1);
    $this->travelTo(now()->addDays(2));
    $unit = ReservationUnit::factory()->for($s['reservation'])->checkedIn()->create(['room_type_id' => $s['units'][0]->room_type_id, 'arrival_date' => '2026-10-09', 'departure_date' => '2026-10-15']);
    app(CreateFolio::class)($s['reservation'], $unit);

    $rows = collect($this->actingAs($s['user'])->postJson(gcUrl($s, 'preview'))->assertOk()->json('rows'))->keyBy('unitId');

    expect($rows[$unit->id]['group'])->toBe('later')
        ->and($rows[$unit->id]['unit'])->toBeNull()
        ->and($rows[$unit->id]['note'])->toBe('The night audit for 10 Oct has not run. Run it first, so the night of 10 Oct is charged.');
});

test('during a support session the owner money switch refuses a group refund row and a check-out without one still saves', function () {
    $s = gcSetup(2);
    gcCharge($s, $s['folios'][0], '45.00');
    PropertySetting::query()->where('property_id', $s['property']->id)->update(['allow_support_money_corrections' => false]);
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('view-as.store', $s['property']->code), ['user' => $s['user']->id, 'reason' => 'support'])
        ->assertRedirect();

    gcSave($this, $s, [['unit_id' => $s['units'][0]->id, 'choice' => 'refund', 'amount' => '10.00']])->assertSessionHasErrors();
    expect($s['units'][0]->fresh()->status)->toBe(UnitStatus::CheckedIn);

    gcSave($this, $s, [['unit_id' => $s['units'][1]->id, 'choice' => null]])->assertSessionHasNoErrors();
    expect($s['units'][1]->fresh()->status)->toBe(UnitStatus::CheckedOut);
});

test('a checked in room refunds an advance credit that is not a deposit', function () {
    $s = gcSetup(2);
    gcDeposit($s, $s['units'][0], '200.00', PaymentPurpose::Advance);

    $response = $this->actingAs($s['user'])->postJson(gcUrl($s, 'preview'))->assertOk();
    $row = collect($response->json('rows'))->keyBy('unitId')[$s['units'][0]->id];

    expect($row['choices'])->toContain('refund')
        ->and($row['refundAmount'])->toBe('200.00');

    gcSave($this, $s, [['unit_id' => $s['units'][0]->id, 'choice' => 'refund', 'amount' => '200.00']])->assertSessionHasNoErrors();

    expect($s['units'][0]->fresh()->status)->toBe(UnitStatus::CheckedOut)
        ->and(Payment::query()->whereNotNull('refund_of_id')->sole()->amount)->toBe('200.00')
        ->and(gcBalance($s['folios'][0]))->toBe('0.00');
});

/**
 * Check every room of the set-up out (their unit folios are at 0), then ask for the group page.
 *
 * @return TestResponse
 */
function gcAllOutPage(object $test, array $setup, ?User $as = null)
{
    gcSave($test, $setup, collect($setup['units'])->map(fn (ReservationUnit $unit): array => ['unit_id' => $unit->id])->all())->assertSessionHasNoErrors();
    // The save flashes its success state to the next page request: show it once, so the next request is a plain visit.
    $test->actingAs($setup['user'])->get(route('reservations.check-out', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]))->assertOk();

    return $test->actingAs($as ?? $setup['user'])->get(route('reservations.check-out', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]));
}

test('with every room out and the Master holding money for a company the page stays open and offers to settle it', function () {
    $s = gcSetup(2);
    gcCompany($s);

    gcAllOutPage($this, $s)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('reservations/check-out')
        ->has('checkOut.preview.rows', 0)
        ->has('checkOut.preview.openFolios', 1)
        ->where('checkOut.preview.openFolios.0.key', (string) $s['master']->id)
        ->where('checkOut.preview.openFolios.0.balance', '80.00')
        ->where('checkOut.preview.openFolios.0.canSettleToCityLedger', true)
        ->where('checkOut.preview.openFolios.0.canRecordPayment', true)
        ->where('checkOut.preview.openFolios.0.url', route('reservations.folio', ['property' => $s['property']->code, 'reservation' => $s['reservation']->id, 'folio' => $s['master']->id])));
});

test('a Master with no company can take a payment but not settle', function () {
    $s = gcSetup(2);
    gcCharge($s, $s['master'], '80.00');

    gcAllOutPage($this, $s)->assertInertia(fn (Assert $page) => $page
        ->has('checkOut.preview.openFolios', 1)
        ->where('checkOut.preview.openFolios.0.canSettleToCityLedger', false)
        ->where('checkOut.preview.openFolios.0.canRecordPayment', true));
});

test('a user without city-ledger.invoice cannot settle the Master', function () {
    $s = gcSetup(2);
    gcCompany($s);
    $clerk = User::factory()->for($s['property'])->withPermissions(['reservations.view', 'front-desk.check-out', 'payment.record'])->create();

    gcAllOutPage($this, $s, $clerk)->assertInertia(fn (Assert $page) => $page
        ->where('checkOut.preview.openFolios.0.canSettleToCityLedger', false)
        ->where('checkOut.preview.openFolios.0.canRecordPayment', true));
});

test('a third party folio that holds money is listed after the Master, and one at 0 is not', function () {
    $s = gcSetup(2);
    gcCompany($s);
    $agent = Company::factory()->for($s['property'])->create();
    $owing = app(CreateFolio::class)->thirdParty($s['reservation'], null, $agent->id);
    gcCharge($s, $owing, '120.00');
    $guest = Guest::factory()->for($s['property'])->create();
    $empty = app(CreateFolio::class)->thirdParty($s['reservation'], $guest);

    gcAllOutPage($this, $s)->assertInertia(fn (Assert $page) => $page
        ->has('checkOut.preview.openFolios', 2)
        ->where('checkOut.preview.openFolios.0.key', (string) $s['master']->id)
        ->where('checkOut.preview.openFolios.1.key', (string) $owing->id)
        ->where('checkOut.preview.openFolios.1.payerCompanyName', $agent->name)
        ->where('checkOut.preview.openFolios.1.balance', '120.00'));

    expect($empty->fresh()->status)->toBe(FolioStatus::Open);
});

test('with every room out and no folio holding money the page redirects to the reservation with a toast', function () {
    $s = gcSetup(2);
    app(CreateFolio::class)->thirdParty($s['reservation'], Guest::factory()->for($s['property'])->create());

    gcAllOutPage($this, $s)
        ->assertRedirect(route('reservations.show', ['property' => $s['property']->code, 'reservation' => $s['reservation']->id]))
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'No rooms are left to check out.']);
});

test('a Master already settled to the city ledger does not keep the page open', function () {
    $s = gcSetup(2);
    gcCompany($s);
    gcSave($this, $s, [['unit_id' => $s['units'][0]->id], ['unit_id' => $s['units'][1]->id]], ['settle_master' => true])->assertSessionHasNoErrors();
    $this->actingAs($s['user'])->get(route('reservations.check-out', ['property' => $s['property']->code, 'reservation' => $s['reservation']->id]))->assertOk();

    $this->actingAs($s['user'])
        ->get(route('reservations.check-out', ['property' => $s['property']->code, 'reservation' => $s['reservation']->id]))
        ->assertRedirect(route('reservations.show', ['property' => $s['property']->code, 'reservation' => $s['reservation']->id]));
});

test('the folios list is not built while a room is left to check out', function () {
    $s = gcSetup(2);
    gcCompany($s);

    $this->actingAs($s['user'])->get(route('reservations.check-out', ['property' => $s['property']->code, 'reservation' => $s['reservation']->id]))
        ->assertInertia(fn (Assert $page) => $page->has('checkOut.preview.rows', 2)->has('checkOut.preview.openFolios', 0));
});
