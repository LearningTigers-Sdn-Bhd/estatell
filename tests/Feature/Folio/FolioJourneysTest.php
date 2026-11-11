<?php

use App\Actions\CityLedger\AutoAllocateArPayment;
use App\Actions\CityLedger\IssueCreditNote;
use App\Actions\CityLedger\RecordArPayment;
use App\Actions\CityLedger\ReverseAllocation;
use App\Actions\CityLedger\SettleToCityLedger;
use App\Actions\Folio\CloseFolio;
use App\Actions\Folio\CreateFolio;
use App\Actions\Folio\CreateRoutingRule;
use App\Actions\Folio\MoveCharges;
use App\Actions\Folio\MoveChargesResult;
use App\Actions\Folio\PostCharge;
use App\Actions\Folio\PostManualBatch;
use App\Actions\Folio\RemoveRoutingRule;
use App\Actions\Folio\ReopenFolio;
use App\Actions\Folio\ReverseLine;
use App\Actions\Folio\SyncRoutingRules;
use App\Actions\Folio\TransferLines;
use App\Actions\Folio\VoidInvoiceAndReopen;
use App\Actions\FrontDesk\CancelUnit;
use App\Actions\FrontDesk\CheckInAndCollect;
use App\Actions\FrontDesk\CheckOutUnit;
use App\Actions\FrontDesk\ExtendStay;
use App\Actions\FrontDesk\UndoCheckOut;
use App\Actions\FrontDesk\UnitStayResult;
use App\Actions\NightAudit\PostRoomCharges;
use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\RefundFolioCredit;
use App\Actions\Payments\RefundPayment;
use App\Actions\Reservations\UpdateReservationCompany;
use App\Actions\Reservations\UpdateReservationGroup;
use App\Data\CityLedger\NewArPaymentData;
use App\Data\Folio\ManualRowData;
use App\Data\Folio\NewChargeData;
use App\Data\Payments\NewPaymentData;
use App\Data\Reservations\CollectNowData;
use App\Enums\ArInvoiceStatus;
use App\Enums\BillingMode;
use App\Enums\BusinessDateStatus;
use App\Enums\DefaultRoleEnum;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\ManualRowType;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Enums\PolicyScope;
use App\Enums\RoutingRuleSource;
use App\Enums\SystemTransactionCode;
use App\Enums\TaxRateType;
use App\Enums\TransactionCategory;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
use App\Models\ArInvoice;
use App\Models\ArPayment;
use App\Models\BusinessDate;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PropertySetting;
use App\Models\Rate;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomRevenuePolicy;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\CityLedger\ArInvoiceBalance;
use App\Services\Folio\FolioBalance;
use App\Services\Reservations\AgentPaymentSchedule;
use Carbon\CarbonImmutable;

/*
 * End-to-end folio journeys: each test runs a whole story through the real Actions and checks the money at the end. The
 * single Actions and their refusals have their own tests. The IDs match docs/13-folio/09-folio-test-cases.md.
 */

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * Two checked-in rooms for one night (10 to 11 Oct) at 100.00 on an open business date of 10 Oct, an extras code, a cash
 * method and the City ledger code. `$company` adds a company with a Master folio paid by it. Routing follows the mode.
 *
 * @param  array<string, mixed>|null  $company
 * @return array{user: User, property: mixed, reservation: Reservation, room: TransactionCode, extra: TransactionCode, cash: PaymentMethod, company: ?Company, units: list<ReservationUnit>, unitCount: int}
 */
function fj(BillingMode $mode = BillingMode::MasterPays, ?array $company = null, int $unitCount = 2): array
{
    $user = User::factory()->withRole(DefaultRoleEnum::Owner)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-10']);
    $room = TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $property->id, 'category' => TransactionCategory::Room]);
    $extra = TransactionCode::factory()->create(['property_id' => $property->id, 'category' => TransactionCategory::Other, 'name' => 'Minibar']);
    TransactionCode::factory()->system(SystemTransactionCode::DirectBillPayment)->create(['property_id' => $property->id]);
    TransactionCode::factory()->system(SystemTransactionCode::Refund)->create(['property_id' => $property->id]);
    $companyRow = $company === null ? null : Company::factory()->for($property)->create(['payment_terms_days' => 30, 'direct_bill_enabled' => true, ...$company]);
    $guest = Guest::factory()->create(['property_id' => $property->id, 'nationality_code' => 'CN']);
    $reservation = Reservation::factory()->create(['property_id' => $property->id, 'booker_guest_id' => $guest->id, 'billing_mode' => $mode, 'company_id' => $companyRow?->id]);
    $units = [];

    for ($index = 0; $index < $unitCount; $index++) {
        $unit = ReservationUnit::factory()->for($reservation)->withNights()->checkedIn()->create(['arrival_date' => '2026-10-10', 'departure_date' => '2026-10-11', 'adults' => 2]);
        UnitNight::query()->where('reservation_unit_id', $unit->id)->update(['base_amount' => '100.00', 'amount' => '100.00']);
        $units[] = $unit;
    }

    app(SyncRoutingRules::class)($reservation);

    return [
        'user' => $user, 'property' => $property, 'reservation' => $reservation, 'room' => $room, 'extra' => $extra, 'company' => $companyRow, 'units' => $units, 'unitCount' => $unitCount,
        'cash' => PaymentMethod::factory()->create(['property_id' => $property->id, 'type' => PaymentMethodType::Cash, 'requires_reference' => false]),
    ];
}

function fjTourismTax(array $setup): Tax
{
    $tax = Tax::factory()->for($setup['property'])->create(['name' => 'Tourism tax', 'rate_type' => TaxRateType::Flat, 'rate' => '10.00', 'foreign_guests_only' => true, 'collect_at_check_in' => true, 'enabled' => true]);
    $tax->applicableTransactionCodes()->attach($setup['room']);

    return $tax;
}

function fjSst(array $setup): Tax
{
    $tax = Tax::factory()->for($setup['property'])->create(['name' => 'SST', 'rate_type' => TaxRateType::Percent, 'rate' => '8.00', 'is_inclusive' => false, 'enabled' => true]);
    $tax->applicableTransactionCodes()->attach($setup['room']);

    return $tax;
}

/** Posts tonight's room charge of every unit. */
function fjNight(array $setup): void
{
    foreach ($setup['units'] as $unit) {
        expect(app(PostRoomCharges::class)(null, $unit, CarbonImmutable::parse('2026-10-10'))->refusal)->toBeNull();
    }
}

/** The night audit has run: the business date moves on, so the rooms may check out. */
function fjNextDay(array $setup): void
{
    test()->travelTo(now()->setDate(2026, 10, 11)->setTime(10, 0));
    BusinessDate::query()->where('property_id', $setup['property']->id)->update(['status' => BusinessDateStatus::Closed, 'closed_at' => now()]);
    BusinessDate::factory()->create(['property_id' => $setup['property']->id, 'date' => '2026-10-11']);
}

function fjExtra(array $setup, int $unit, string $amount = '30.00', ?Folio $folio = null): FolioLine
{
    $result = app(PostCharge::class)($setup['user'], $setup['units'][$unit]->fresh(), NewChargeData::from([
        'chargeItemId' => null,
        'transactionCodeId' => $setup['extra']->id,
        'description' => 'Minibar',
        'quantity' => 1,
        'unitAmount' => $amount,
        'idempotencyKey' => 'manual:'.fake()->uuid(),
        'folioId' => $folio?->id,
    ]));

    expect($result->isRefused())->toBeFalse();

    return $result->line();
}

function fjPay(array $setup, Folio $folio, string $amount, PaymentPurpose $purpose = PaymentPurpose::Settlement, ?string $key = null): Payment
{
    $result = app(RecordPayment::class)($setup['user'], $setup['reservation'], $folio->fresh(), null, NewPaymentData::from([
        'paymentMethodId' => $setup['cash']->id, 'purpose' => $purpose, 'amount' => $amount, 'reference' => null, 'idempotencyKey' => $key ?? fake()->uuid(),
    ]));

    expect($result->isRefused())->toBeFalse();

    return $result->payment;
}

function fjMaster(array $setup): Folio
{
    return Folio::query()->where('reservation_id', $setup['reservation']->id)->where('type', FolioType::Master)->firstOrFail();
}

function fjUnitFolio(array $setup, int $unit): ?Folio
{
    return Folio::query()->where('reservation_unit_id', $setup['units'][$unit]->id)->where('type', FolioType::Unit)->first();
}

function fjBalance(?Folio $folio): string
{
    return $folio === null ? '0.00' : app(FolioBalance::class)->forFolio($folio->fresh());
}

function fjCheckOut(array $setup, int $unit): string
{
    $result = app(CheckOutUnit::class)($setup['user'], $setup['units'][$unit]->fresh());

    return $result->isRefused() ? (string) $result->refusal : 'out';
}

/** Every line of the reservation adds up to the sum of its folio balances, and no ledger line was changed. */
/**
 * Close the business date of 11 Oct and open 12 Oct, with no night posted for 11 Oct.
 */
function fjNight2(array $setup): void
{
    test()->travelTo(now()->setDate(2026, 10, 12)->setTime(10, 0));
    BusinessDate::query()->where('property_id', $setup['property']->id)->update(['status' => BusinessDateStatus::Closed, 'closed_at' => now()]);
    BusinessDate::factory()->create(['property_id' => $setup['property']->id, 'date' => '2026-10-12']);
}

/**
 * Check a room out with its balance still open, as a checked-out past stay is.
 */
function fjCheckOutWithBalance(array $setup, ReservationUnit $unit): void
{
    expect(app(CheckOutUnit::class)($setup['user'], $unit, null, true)->isRefused())->toBeFalse();
}

function fjBalanced(array $setup): void
{
    $folios = Folio::query()->where('reservation_id', $setup['reservation']->id)->get();
    $sum = '0.00';

    foreach ($folios as $folio) {
        $sum = bcadd($sum, app(FolioBalance::class)->forFolio($folio), 2);
    }

    expect($sum)->toBe(number_format((float) FolioLine::query()->whereIn('folio_id', $folios->modelKeys())->sum('amount'), 2, '.', ''));
}

/** Every reversal line cancels its original exactly. */
function fjReversalsCancel(array $setup): void
{
    $folioIds = Folio::query()->where('reservation_id', $setup['reservation']->id)->pluck('id');

    foreach (FolioLine::query()->whereIn('folio_id', $folioIds)->whereNotNull('reversal_of_id')->get() as $reversal) {
        expect(bcadd((string) $reversal->amount, (string) FolioLine::query()->findOrFail($reversal->reversal_of_id)->amount, 2))->toBe('0.00');
    }
}

// A. Who gets the charges

test('A1 main room pays all: every charge moves to room A, room B leaves at 0 and room A pays once', function () {
    $setup = fj(BillingMode::EachPays);
    $extra = fjExtra($setup, 1);
    $folioA = app(CreateFolio::class)($setup['reservation'], $setup['units'][0]);

    expect(app(MoveCharges::class)($setup['user'], $folioA, [$extra], [])->refusal)->toBeNull()
        ->and(app(CreateRoutingRule::class)($setup['reservation'], $folioA, RoutingRuleSource::Manual, $setup['units'][1]->id)->isRefused())->toBeFalse();

    fjNight($setup);
    fjNextDay($setup);

    expect(fjBalance(fjUnitFolio($setup, 1)))->toBe('0.00')
        ->and(fjBalance($folioA))->toBe('230.00')
        ->and(fjCheckOut($setup, 1))->toBe('out')
        ->and(fjCheckOut($setup, 0))->toBe(UnitStayResult::REFUSAL_BALANCE_NOT_SETTLED);

    fjPay($setup, $folioA, '230.00');

    expect(fjCheckOut($setup, 0))->toBe('out')
        ->and($folioA->fresh()->status)->toBe(FolioStatus::Closed);
    fjBalanced($setup);
    fjReversalsCancel($setup);
});

test('A2 the main room cannot leave while another room still charges to it, until the rule changes', function () {
    $setup = fj(BillingMode::EachPays);
    $folioA = app(CreateFolio::class)($setup['reservation'], $setup['units'][0]);
    $rule = app(CreateRoutingRule::class)($setup['reservation'], $folioA, RoutingRuleSource::Manual, $setup['units'][1]->id);
    app(PostRoomCharges::class)(null, $setup['units'][0], CarbonImmutable::parse('2026-10-10'));
    fjPay($setup, $folioA, '100.00');
    fjNextDay($setup);

    expect(fjCheckOut($setup, 0))->toBe(UnitStayResult::REFUSAL_RECEIVES_SCHEDULED_CHARGES)
        ->and($setup['units'][0]->fresh()->status)->toBe(UnitStatus::CheckedIn);

    expect(app(RemoveRoutingRule::class)($setup['user'], $rule->rule)->isRefused())->toBeFalse()
        ->and(fjCheckOut($setup, 0))->toBe('out');
});

test('A8 Move charges sends tonight\'s night of a room on its last night, and refuses once the night is posted', function () {
    $setup = fj(BillingMode::EachPays);
    $folioA = app(CreateFolio::class)($setup['reservation'], $setup['units'][0]);
    $schedule = [['unit' => $setup['units'][1], 'scope' => 'room']];

    expect(app(MoveCharges::class)($setup['user'], $folioA, [], $schedule)->scheduleCount)->toBe(1);

    fjNight($setup);

    expect(fjBalance($folioA))->toBe('200.00')
        ->and(fjBalance(fjUnitFolio($setup, 1)))->toBe('0.00')
        ->and(app(MoveCharges::class)($setup['user'], $folioA, [], $schedule)->refusal)->toBe(MoveChargesResult::REFUSAL_NOTHING_SCHEDULED);
});

test('A3 Master pays: rooms go to the Master, extras to their room, and each is paid on its own folio', function () {
    $setup = fj(BillingMode::MasterPays);
    fjNight($setup);
    fjExtra($setup, 0);
    fjNextDay($setup);
    $master = fjMaster($setup);

    expect(fjBalance($master))->toBe('200.00')
        ->and(fjBalance(fjUnitFolio($setup, 0)))->toBe('30.00')
        ->and(fjCheckOut($setup, 0))->toBe(UnitStayResult::REFUSAL_BALANCE_NOT_SETTLED);

    fjPay($setup, fjUnitFolio($setup, 0), '30.00');

    expect(fjCheckOut($setup, 0))->toBe('out')
        ->and(fjCheckOut($setup, 1))->toBe('out');

    fjPay($setup, $master, '200.00');

    expect(app(CloseFolio::class)($setup['user'], $master->fresh())->isRefused())->toBeFalse();
    fjBalanced($setup);
});

test('A4 Master pays all: extras and rooms go to the Master and the rooms leave with nothing owed', function () {
    $setup = fj(BillingMode::MasterPaysAll);
    fjNight($setup);
    fjExtra($setup, 1);
    fjNextDay($setup);

    expect(fjBalance(fjMaster($setup)))->toBe('230.00')
        ->and(fjCheckOut($setup, 0))->toBe('out')
        ->and(fjCheckOut($setup, 1))->toBe('out')
        ->and(fjBalance(fjMaster($setup)))->toBe('230.00');
});

test('A5 each pays: no Master folio, and one room cannot pay for another by accident', function () {
    $setup = fj(BillingMode::EachPays);
    fjNight($setup);
    fjNextDay($setup);

    expect(Folio::query()->where('reservation_id', $setup['reservation']->id)->where('type', FolioType::Master)->exists())->toBeFalse();

    fjPay($setup, fjUnitFolio($setup, 0), '100.00');

    expect(fjCheckOut($setup, 0))->toBe('out')
        ->and(fjCheckOut($setup, 1))->toBe(UnitStayResult::REFUSAL_BALANCE_NOT_SETTLED);
});

test('A6 a charge moved with its tax can be reversed on the new folio, and the money stays right', function () {
    $setup = fj(BillingMode::EachPays);
    fjSst($setup);
    fjNight($setup);
    $folioA = fjUnitFolio($setup, 0);
    $folioB = fjUnitFolio($setup, 1);
    $charge = FolioLine::query()->where('folio_id', $folioB->id)->where('kind', TransactionKind::Charge)->sole();

    $transfer = app(TransferLines::class)($setup['user'], [$charge], $folioA);
    expect($transfer->isRefused())->toBeFalse()
        ->and(fjBalance($folioB))->toBe('0.00')
        ->and(fjBalance($folioA))->toBe('216.00');

    $copy = FolioLine::query()->where('folio_id', $folioA->id)->where('kind', TransactionKind::Charge)->whereNotNull('transfer_group_id')->whereNull('reversal_of_id')->sole();
    expect(app(ReverseLine::class)($setup['user'], $copy)->isRefused())->toBeFalse()
        ->and(fjBalance($folioA))->toBe('108.00');
    fjBalanced($setup);
    fjReversalsCancel($setup);
});

test('A7 a third-party folio takes one kind of charge and is invoiced while the guests stay', function () {
    $setup = fj(BillingMode::EachPays, ['name' => 'Dive Agent']);
    $third = Folio::factory()->create(['type' => FolioType::ThirdParty, 'property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id, 'payer_company_id' => $setup['company']->id, 'reservation_unit_id' => null]);
    app(CreateRoutingRule::class)($setup['reservation'], $third, RoutingRuleSource::Manual, null, null, $setup['extra']->id);

    fjNight($setup);
    fjExtra($setup, 0);

    expect(fjBalance($third))->toBe('30.00')
        ->and(fjBalance(fjUnitFolio($setup, 0)))->toBe('100.00');

    $settled = app(SettleToCityLedger::class)($setup['user'], $third->fresh());

    expect($settled->isRefused())->toBeFalse()
        ->and($settled->invoice->amount)->toBe('30.00')
        ->and($setup['units'][0]->fresh()->status)->toBe(UnitStatus::CheckedIn);
});

// B. City ledger

test('B1 charge to city ledger: rooms leave, the Master is invoiced in full, the agent pays and the invoice is settled', function () {
    $setup = fj(BillingMode::MasterPays, []);
    fjNight($setup);
    fjNextDay($setup);
    fjCheckOut($setup, 0);
    fjCheckOut($setup, 1);
    $master = fjMaster($setup);

    $settled = app(SettleToCityLedger::class)($setup['user'], $master);

    expect($settled->isRefused())->toBeFalse()
        ->and($settled->invoice->amount)->toBe('200.00')
        ->and(fjBalance($master))->toBe('0.00')
        ->and($master->fresh()->status)->toBe(FolioStatus::Closed);

    $payment = app(RecordArPayment::class)($setup['user'], $setup['company'], new NewArPaymentData($setup['cash']->id, '200.00', fake()->uuid()), allocate: true);

    expect($payment->isRefused())->toBeFalse()
        ->and($settled->invoice->fresh()->status)->toBe(ArInvoiceStatus::Settled);
    fjBalanced($setup);
});

test('B2 a deposit first: only what is left goes to the city ledger', function () {
    $setup = fj(BillingMode::MasterPays, []);
    $master = fjMaster($setup);
    fjPay($setup, $master, '50.00', PaymentPurpose::Advance);
    fjNight($setup);
    fjNextDay($setup);
    fjCheckOut($setup, 0);
    fjCheckOut($setup, 1);

    $settled = app(SettleToCityLedger::class)($setup['user'], $master->fresh());

    expect($settled->invoice->amount)->toBe('150.00')
        ->and(fjBalance($master))->toBe('0.00');
});

test('B3 the guest pays extras at the desk and the rooms go to the city ledger', function () {
    $setup = fj(BillingMode::MasterPays, []);
    fjNight($setup);
    fjExtra($setup, 0);
    fjNextDay($setup);
    fjPay($setup, fjUnitFolio($setup, 0), '30.00');
    fjCheckOut($setup, 0);
    fjCheckOut($setup, 1);

    expect(app(SettleToCityLedger::class)($setup['user'], fjMaster($setup))->invoice->amount)->toBe('200.00')
        ->and(fjUnitFolio($setup, 0)->fresh()->status)->toBe(FolioStatus::Closed);
});

test('B4 extras moved to the Master before check-out go on the same invoice', function () {
    $setup = fj(BillingMode::MasterPays, []);
    fjNight($setup);
    $extra = fjExtra($setup, 0);
    fjNextDay($setup);
    $master = fjMaster($setup);

    app(TransferLines::class)($setup['user'], [$extra], $master);
    fjCheckOut($setup, 0);
    fjCheckOut($setup, 1);

    expect(fjUnitFolio($setup, 0)->fresh()->status)->toBe(FolioStatus::Closed)
        ->and(app(SettleToCityLedger::class)($setup['user'], $master->fresh())->invoice->amount)->toBe('230.00');
});

test('B5 a late charge after settling is refused on the Master and makes a second invoice on a third-party folio', function () {
    $setup = fj(BillingMode::MasterPays, []);
    fjNight($setup);
    fjNextDay($setup);
    fjCheckOut($setup, 0);
    fjCheckOut($setup, 1);
    $master = fjMaster($setup);
    app(SettleToCityLedger::class)($setup['user'], $master);

    $third = Folio::factory()->create(['type' => FolioType::ThirdParty, 'property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id, 'payer_company_id' => $setup['company']->id, 'reservation_unit_id' => null]);
    fjExtra($setup, 0, '45.00', $third);
    $second = app(SettleToCityLedger::class)($setup['user'], $third->fresh());

    expect($second->invoice->amount)->toBe('45.00')
        ->and(ArInvoice::query()->where('company_id', $setup['company']->id)->count())->toBe(2)
        ->and($second->invoice->number)->not->toBe(ArInvoice::query()->where('folio_id', $master->id)->sole()->number);
});

test('B6 a credit note lowers what the agent owes and the folio stays closed', function () {
    $setup = fj(BillingMode::MasterPays, []);
    fjNight($setup);
    fjNextDay($setup);
    fjCheckOut($setup, 0);
    fjCheckOut($setup, 1);
    $master = fjMaster($setup);
    $invoice = app(SettleToCityLedger::class)($setup['user'], $master)->invoice;

    expect(app(IssueCreditNote::class)($setup['user'], $invoice, '50.00', 'Goodwill')->isRefused())->toBeFalse();

    $payment = app(RecordArPayment::class)($setup['user'], $setup['company'], new NewArPaymentData($setup['cash']->id, '150.00', fake()->uuid()), allocate: true);

    expect($payment->isRefused())->toBeFalse()
        ->and($invoice->fresh()->status)->toBe(ArInvoiceStatus::Settled)
        ->and($master->fresh()->status)->toBe(FolioStatus::Closed);
});

test('B7 void and reopen after a payment: reverse the payment, fix the folio, settle again with the same number', function () {
    $setup = fj(BillingMode::MasterPays, []);
    fjNight($setup);
    fjNextDay($setup);
    fjCheckOut($setup, 0);
    fjCheckOut($setup, 1);
    $master = fjMaster($setup);
    $invoice = app(SettleToCityLedger::class)($setup['user'], $master)->invoice;
    $number = $invoice->number;
    app(RecordArPayment::class)($setup['user'], $setup['company'], new NewArPaymentData($setup['cash']->id, '200.00', fake()->uuid()), allocate: true);

    expect(app(VoidInvoiceAndReopen::class)($setup['user'], $master->fresh())->isRefused())->toBeTrue();

    $allocation = ArPayment::query()->sole()->allocations()->sole();
    app(ReverseAllocation::class)($setup['user'], $allocation, 'Wrong invoice');

    expect(app(VoidInvoiceAndReopen::class)($setup['user'], $master->fresh())->isRefused())->toBeFalse()
        ->and(fjBalance($master))->toBe('200.00');

    fjExtra($setup, 0, '20.00', $master->fresh());
    $again = app(SettleToCityLedger::class)($setup['user'], $master->fresh());

    expect($again->invoice->number)->toBe($number)
        ->and($again->invoice->amount)->toBe('220.00')
        ->and($invoice->fresh()->status)->toBe(ArInvoiceStatus::Void);

    expect(app(AutoAllocateArPayment::class)($setup['user'], ArPayment::query()->sole())->isRefused())->toBeFalse()
        ->and(app(ArInvoiceBalance::class)->forInvoice($again->invoice->fresh()))->toBe('20.00');
    fjBalanced($setup);
    fjReversalsCancel($setup);
});

test('B8 settling is refused while a room stays, and with no company, and twice', function () {
    $setup = fj(BillingMode::MasterPays, []);
    fjNight($setup);
    $master = fjMaster($setup);

    expect(app(SettleToCityLedger::class)($setup['user'], $master)->refusal)->toBe('units_in_house');

    fjNextDay($setup);
    fjCheckOut($setup, 0);
    fjCheckOut($setup, 1);
    app(SettleToCityLedger::class)($setup['user'], $master->fresh());

    expect(app(SettleToCityLedger::class)($setup['user'], $master->fresh())->refusal)->toBe('folio_not_open')
        ->and(ArInvoice::query()->count())->toBe(1);

    $noCompany = fj(BillingMode::MasterPays);
    fjNight($noCompany);
    fjNextDay($noCompany);
    fjCheckOut($noCompany, 0);
    fjCheckOut($noCompany, 1);

    expect(app(SettleToCityLedger::class)($noCompany['user'], fjMaster($noCompany))->refusal)->toBe('no_company_payer');
});

// C. Payments

test('C1 post a payment after check-out: refused on a closed folio, then reopen, charge, pay, and it closes again with its number', function () {
    $setup = fj(BillingMode::EachPays, null, 1);
    fjNight($setup);
    fjNextDay($setup);
    $folio = fjUnitFolio($setup, 0);
    fjPay($setup, $folio, '100.00');
    fjCheckOut($setup, 0);
    $number = $folio->fresh()->invoice_number;

    expect($folio->fresh()->status)->toBe(FolioStatus::Closed)->and($number)->not->toBeNull();

    $refused = app(RecordPayment::class)($setup['user'], $setup['reservation'], $folio->fresh(), null, NewPaymentData::from([
        'paymentMethodId' => $setup['cash']->id, 'purpose' => PaymentPurpose::Settlement, 'amount' => '10.00', 'reference' => null, 'idempotencyKey' => fake()->uuid(),
    ]));
    expect($refused->refusal)->toBe('folio_not_open');

    expect(app(ReopenFolio::class)($setup['user'], $folio->fresh())->isRefused())->toBeFalse();
    fjExtra($setup, 0, '15.00', $folio->fresh());
    fjPay($setup, $folio, '15.00');

    expect($folio->fresh()->status)->toBe(FolioStatus::Closed)
        ->and($folio->fresh()->invoice_number)->toBe($number)
        ->and(fjBalance($folio))->toBe('0.00');
});

test('C2 split payment: cash and card together settle one folio, each with its receipt', function () {
    $setup = fj(BillingMode::EachPays, null, 1);
    fjNight($setup);
    fjNextDay($setup);
    $folio = fjUnitFolio($setup, 0);
    $first = fjPay($setup, $folio, '60.00');
    $second = fjPay($setup, $folio, '40.00');

    expect($first->receipt_number)->not->toBe($second->receipt_number)
        ->and(fjBalance($folio))->toBe('0.00')
        ->and(fjCheckOut($setup, 0))->toBe('out');
});

test('C3 a double click records one payment', function () {
    $setup = fj(BillingMode::EachPays, null, 1);
    fjNight($setup);
    $folio = fjUnitFolio($setup, 0);
    $key = fake()->uuid();

    fjPay($setup, $folio, '100.00', PaymentPurpose::Settlement, $key);
    fjPay($setup, $folio, '100.00', PaymentPurpose::Settlement, $key);

    expect(Payment::query()->count())->toBe(1)
        ->and(fjBalance($folio))->toBe('0.00');
});

test('C4 overpayment: the credit blocks nothing it should not, is refunded, and the folio closes at 0', function () {
    $setup = fj(BillingMode::EachPays, null, 1);
    fjNight($setup);
    fjNextDay($setup);
    $folio = fjUnitFolio($setup, 0);
    fjPay($setup, $folio, '150.00');

    expect(fjBalance($folio))->toBe('-50.00');

    $refund = app(RefundFolioCredit::class)($setup['user'], $folio->fresh(), '50.00', null, null, fake()->uuid());

    expect($refund->isRefused())->toBeFalse()
        ->and(fjBalance($folio))->toBe('0.00')
        ->and(fjCheckOut($setup, 0))->toBe('out');
    fjBalanced($setup);
});

test('C5 a reversed charge leaves a credit to refund, and the refund cannot pass what was paid', function () {
    $setup = fj(BillingMode::EachPays, null, 1);
    $extra = fjExtra($setup, 0, '40.00');
    $folio = fjUnitFolio($setup, 0);
    $payment = fjPay($setup, $folio, '40.00');

    app(ReverseLine::class)($setup['user'], $extra);

    expect(fjBalance($folio))->toBe('-40.00');

    $tooMuch = app(RefundPayment::class)($setup['user'], $payment, '40.01', null, fake()->uuid());
    expect($tooMuch->isRefused())->toBeTrue();

    expect(app(RefundPayment::class)($setup['user'], $payment, '40.00', null, fake()->uuid())->isRefused())->toBeFalse()
        ->and(fjBalance($folio))->toBe('0.00');
    fjReversalsCancel($setup);
});

test('C6 an advance goes to the Master when rooms charge there, and a unit folio refuses it', function () {
    $setup = fj(BillingMode::MasterPays);
    $refused = app(RecordPayment::class)($setup['user'], $setup['reservation'], null, $setup['units'][0], NewPaymentData::from([
        'paymentMethodId' => $setup['cash']->id, 'purpose' => PaymentPurpose::Advance, 'amount' => '50.00', 'reference' => null, 'idempotencyKey' => fake()->uuid(),
    ]));

    expect($refused->refusal)->toBe('advance_on_master');

    fjPay($setup, fjMaster($setup), '50.00', PaymentPurpose::Advance);
    fjNight($setup);

    expect(fjBalance(fjMaster($setup)))->toBe('150.00');
});

test('C7 the billing mode is locked while the Master holds money, and free again once it is refunded', function () {
    $setup = fj(BillingMode::MasterPays);
    $payment = fjPay($setup, fjMaster($setup), '50.00', PaymentPurpose::Advance);
    $change = fn () => app(UpdateReservationGroup::class)($setup['user'], $setup['reservation']->fresh(), null, BillingMode::EachPays);

    expect($change()->refusal)->toBe('master_has_payments');

    app(RefundPayment::class)($setup['user'], $payment, '50.00', null, fake()->uuid());

    expect($change()->isRefused())->toBeFalse();
});

// D. Tourism tax and agents

test('D1 agent books, guest pays tourism tax: the invoice has no tourism tax and the guest must pay it to leave', function () {
    $setup = fj(BillingMode::MasterPays, [], 1);
    fjTourismTax($setup);
    fjNight($setup);
    fjNextDay($setup);
    $guestFolio = fjUnitFolio($setup, 0);

    expect(fjBalance($guestFolio))->toBe('10.00')
        ->and(fjBalance(fjMaster($setup)))->toBe('100.00')
        ->and(fjCheckOut($setup, 0))->toBe(UnitStayResult::REFUSAL_BALANCE_NOT_SETTLED);

    fjPay($setup, $guestFolio, '10.00');

    expect(fjCheckOut($setup, 0))->toBe('out')
        ->and(app(SettleToCityLedger::class)($setup['user'], fjMaster($setup))->invoice->amount)->toBe('100.00');
});

test('D2 agent pays tourism tax: it goes on the invoice and the guest owes nothing', function () {
    $setup = fj(BillingMode::MasterPays, ['pays_collected_taxes' => true], 1);
    fjTourismTax($setup);
    fjNight($setup);
    fjNextDay($setup);

    expect(fjBalance(fjUnitFolio($setup, 0)))->toBe('0.00')
        ->and(fjCheckOut($setup, 0))->toBe('out')
        ->and(app(SettleToCityLedger::class)($setup['user'], fjMaster($setup))->invoice->amount)->toBe('110.00');
});

test('D3 SST follows the room to the agent while tourism tax stays with the guest', function () {
    $setup = fj(BillingMode::MasterPays, [], 1);
    fjTourismTax($setup);
    fjSst($setup);
    fjNight($setup);

    expect(fjBalance(fjMaster($setup)))->toBe('108.00')
        ->and(fjBalance(fjUnitFolio($setup, 0)))->toBe('10.00');
});

test('D4 the agent schedule is met by agent money, the deposit before the stay and the rest after posting', function () {
    $setup = fj(BillingMode::MasterPays, ['direct_bill_enabled' => false], 1);
    fjTourismTax($setup);
    $setup['reservation']->forceFill(['deposit_percent' => 50, 'deposit_due_at' => '2026-10-20 00:00:00', 'full_payment_due_at' => '2026-10-25 00:00:00'])->save();
    $schedule = app(AgentPaymentSchedule::class);

    expect((string) $schedule->total($setup['reservation']))->toBe('100.00');

    fjPay($setup, fjMaster($setup), '50.00', PaymentPurpose::Advance);
    expect($schedule->stages($setup['reservation']->fresh())[0]['paid'])->toBeTrue();

    fjNight($setup);
    expect((string) $schedule->total($setup['reservation']->fresh()))->toBe('100.00');

    fjPay($setup, fjUnitFolio($setup, 0), '10.00');
    expect($schedule->stages($setup['reservation']->fresh())[1]['paid'])->toBeFalse();

    fjPay($setup, fjMaster($setup), '50.00');
    expect($schedule->stages($setup['reservation']->fresh())[1]['paid'])->toBeTrue();
});

test('D5 reversing a night on the agent folio also reverses the tourism tax on the guest folio', function () {
    $setup = fj(BillingMode::MasterPays, [], 1);
    fjTourismTax($setup);
    fjNight($setup);
    $room = FolioLine::query()->where('folio_id', fjMaster($setup)->id)->where('kind', TransactionKind::Charge)->sole();

    expect(app(ReverseLine::class)($setup['user'], $room)->isRefused())->toBeFalse()
        ->and(fjBalance(fjMaster($setup)))->toBe('0.00')
        ->and(fjBalance(fjUnitFolio($setup, 0)))->toBe('0.00');
    fjReversalsCancel($setup);
});

test('D6 a guest on an agent booking pays the tourism tax at check-in: the money lands on their folio and they leave at 0', function () {
    $setup = fj(BillingMode::MasterPays, [], 1);
    fjTourismTax($setup);
    $unit = $setup['units'][0];
    $unit->forceFill(['status' => UnitStatus::Confirmed, 'checked_in_at' => null])->save();
    UnitNight::query()->where('reservation_unit_id', $unit->id)->update(['room_id' => Room::factory()->create(['property_id' => $setup['property']->id])->id]);

    $checkedIn = app(CheckInAndCollect::class)($setup['user'], $unit->fresh(), withoutCard: true, collect: new CollectNowData(true, false, $setup['cash']->id, null, fake()->uuid()));

    expect($checkedIn->paymentRefusal)->toBeNull()
        ->and($checkedIn->stay->isRefused())->toBeFalse()
        ->and(fjBalance(fjUnitFolio($setup, 0)))->toBe('-10.00');

    fjNight($setup);
    fjNextDay($setup);

    expect(fjBalance(fjUnitFolio($setup, 0)))->toBe('0.00')
        ->and(fjCheckOut($setup, 0))->toBe('out')
        ->and(app(SettleToCityLedger::class)($setup['user'], fjMaster($setup))->invoice->amount)->toBe('100.00');
});

// E. Other turns in a stay

test('E1 undo check-out: the folio reopens and a late charge can go on it', function () {
    $setup = fj(BillingMode::EachPays, null, 1);
    fjNight($setup);
    fjNextDay($setup);
    $folio = fjUnitFolio($setup, 0);
    fjPay($setup, $folio, '100.00');
    fjCheckOut($setup, 0);

    $undone = app(UndoCheckOut::class)($setup['user'], $setup['units'][0]->fresh(), 'Guest came back');

    expect($undone->isRefused())->toBeFalse()
        ->and($setup['units'][0]->fresh()->status)->toBe(UnitStatus::CheckedIn)
        ->and($folio->fresh()->status)->toBe(FolioStatus::Open);

    fjExtra($setup, 0, '25.00');

    expect(fjBalance($folio))->toBe('25.00');
});

test('E2 a cancelled room keeps its deposit on the folio until it is refunded', function () {
    $setup = fj(BillingMode::EachPays, null, 1);
    TransactionCode::factory()->system(SystemTransactionCode::CancellationRevenue)->create(['property_id' => $setup['property']->id]);
    RoomRevenuePolicy::factory()->for($setup['property'])->fixed('20.00')->forCode(SystemTransactionCode::CancellationRevenue, PolicyScope::WholeStay)->create();
    $unit = $setup['units'][0];
    $unit->forceFill(['status' => UnitStatus::Confirmed, 'checked_in_at' => null])->save();
    $payment = app(RecordPayment::class)($setup['user'], $setup['reservation'], null, $unit->fresh(), NewPaymentData::from([
        'paymentMethodId' => $setup['cash']->id, 'purpose' => PaymentPurpose::Advance, 'amount' => '60.00', 'reference' => null, 'idempotencyKey' => fake()->uuid(),
    ]))->payment;

    expect(app(CancelUnit::class)($setup['user'], $unit->fresh(), doNotCharge: true, reason: 'Guest cancelled')->refusal)->toBeNull()
        ->and(fjBalance(fjUnitFolio($setup, 0)))->toBe('-60.00');

    expect(app(RefundPayment::class)($setup['user'], $payment, '60.00', null, fake()->uuid())->isRefused())->toBeFalse()
        ->and(fjBalance(fjUnitFolio($setup, 0)))->toBe('0.00');
});

test('E3 a company set after a room was charged: the old lines stay until moved, then the invoice holds them all', function () {
    $setup = fj(BillingMode::MasterPays, null, 1);
    fjNight($setup);
    $company = Company::factory()->for($setup['property'])->create(['payment_terms_days' => 30, 'direct_bill_enabled' => true]);

    $updated = app(UpdateReservationCompany::class)($setup['user'], $setup['reservation']->fresh(), $company->id);

    expect($updated->isRefused())->toBeFalse()
        ->and(fjBalance(fjUnitFolio($setup, 0)))->toBe('100.00');

    $master = fjMaster($setup);
    $charge = FolioLine::query()->where('folio_id', fjUnitFolio($setup, 0)->id)->where('kind', TransactionKind::Charge)->sole();
    expect(app(TransferLines::class)($setup['user'], [$charge], $master)->isRefused())->toBeFalse();

    fjNextDay($setup);

    expect(fjCheckOut($setup, 0))->toBe('out')
        ->and(app(SettleToCityLedger::class)($setup['user'], $master->fresh())->invoice->amount)->toBe('100.00');
    fjBalanced($setup);
});

test('A12 a guest who stayed past the departure and is extended after the audit moved on pays for the missed night', function () {
    $setup = fj(BillingMode::EachPays, null, 1);
    fjNight($setup);
    fjNextDay($setup);
    fjNight2($setup);

    $unit = $setup['units'][0]->fresh();
    Room::factory()->count(3)->for($setup['property'])->create(['room_type_id' => $unit->room_type_id]);
    $unit->ratePlan->roomTypes()->syncWithoutDetaching([$unit->room_type_id]);

    foreach (['2026-10-11', '2026-10-12'] as $date) {
        Rate::factory()->create(['rate_plan_id' => $unit->rate_plan_id, 'room_type_id' => $unit->room_type_id, 'date' => $date, 'amount' => '100.00']);
    }

    $result = app(ExtendStay::class)($setup['user'], $unit, CarbonImmutable::parse($unit->departure_date)->addDays(2));

    expect($result->refusal)->toBeNull();

    $dates = FolioLine::query()->where('folio_id', fjUnitFolio($setup, 0)->id)->whereNull('parent_line_id')
        ->where('idempotency_key', 'like', 'night:%')->orderBy('service_date')->pluck('service_date')->map(fn ($date): string => $date->toDateString())->all();

    expect($dates)->toBe(['2026-10-10', '2026-10-11']);
});

test('A13 a past stay takes a payment dated during the stay, and Close after a clearing payment does not undo it', function () {
    $setup = fj(BillingMode::EachPays, null, 1);
    fjNight($setup);
    fjNextDay($setup);
    $unit = $setup['units'][0]->fresh();
    $folio = fjUnitFolio($setup, 0);
    fjCheckOutWithBalance($setup, $unit);
    $setup['reservation']->forceFill(['created_at' => now()->addDays(3)])->save();

    $result = app(PostManualBatch::class)($setup['user'], $setup['reservation']->fresh(), $folio->fresh(), 'Paper', [ManualRowData::from([
        'key' => 'a', 'type' => ManualRowType::Payment, 'serviceDate' => '2026-10-10', 'folioId' => $folio->id, 'unitId' => $unit->id,
        'amount' => fjBalance($folio), 'paymentMethodId' => $setup['cash']->id, 'quantity' => 1, 'purpose' => PaymentPurpose::Settlement,
    ])], true, 'batch-1');

    expect($result->refusal ?? null)->toBeNull()
        ->and($folio->fresh()->status)->toBe(FolioStatus::Closed)
        ->and(fjBalance($folio))->toBe('0.00');
});
