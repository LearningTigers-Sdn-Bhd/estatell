<?php

use App\Actions\Payments\PaymentResult;
use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\RefundPayment;
use App\Data\Folio\ManualRowData;
use App\Data\Payments\NewPaymentData;
use App\Enums\ManualRowType;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Enums\PermissionEnum;
use App\Enums\SurchargeType;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionCategory;
use App\Models\ChargeItem;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\Folio\FolioBalance;
use App\Services\Folio\FolioPaymentRows;
use App\Services\Folio\ManualFolioPreview;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/ManualFolioSetup.php';

/**
 * The manual folio setup (business date 2026-10-13, first business date 2026-10-10, default 60-day window) with the
 * Old system code and an inactive Old system method. The method carries a surcharge on purpose: it must be ignored.
 *
 * @return array<string, mixed>
 */
function osSetup(): array
{
    $setup = mfSetup();
    setPermissionsTeamId($setup['property']->id);
    $code = TransactionCode::factory()->system(SystemTransactionCode::OldSystemPayment)->create(['property_id' => $setup['property']->id]);
    $fee = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'name' => 'Card fee']);
    $setup['old'] = PaymentMethod::factory()->inactive()->create([
        'property_id' => $setup['property']->id,
        'transaction_code_id' => $code->id,
        'name' => 'Old system',
        'type' => PaymentMethodType::OldSystem,
        'requires_reference' => false,
        'surcharge_type' => SurchargeType::Percent,
        'surcharge_value' => '5.00',
        'surcharge_charge_item_id' => $fee->id,
    ]);

    return $setup;
}

/** @param array<string, mixed> $attributes */
function osPay(array $setup, array $attributes = [], ?User $actor = null, ?PaymentMethod $method = null): PaymentResult
{
    return app(RecordPayment::class)($actor ?? $setup['user'], $setup['reservation'], $setup['folio'], null, NewPaymentData::from([
        'paymentMethodId' => ($method ?? $setup['old'])->id,
        'purpose' => PaymentPurpose::Settlement,
        'amount' => '100.00',
        'reference' => 'OR-1203',
        'idempotencyKey' => fake()->uuid(),
        'serviceDate' => '2026-09-01',
        ...$attributes,
    ]));
}

test('an Old system payment is recorded without a receipt number, dated before go-live, even though the method is inactive', function () {
    $setup = osSetup();

    $result = osPay($setup);
    $payment = $result->payment;

    expect($result->isRefused())->toBeFalse()
        ->and($payment->receipt_number)->toBeNull()
        ->and($payment->reference)->toBe('OR-1203')
        ->and($payment->amount)->toBe('100.00')
        ->and($payment->surcharge_line_id)->toBeNull()
        ->and($payment->folioLine->service_date->toDateString())->toBe('2026-09-01')
        ->and($payment->folioLine->posting_date->toDateString())->toBe('2026-10-13')
        ->and($payment->folioLine->description)->toBe('Paid in old system · OR-1203')
        ->and(FolioLine::query()->where('folio_id', $setup['folio']->id)->count())->toBe(1);
});

test('an Old system payment is refused without folio.manual', function () {
    $setup = osSetup();
    $clerk = User::factory()->for($setup['property'])->withPermissions([PermissionEnum::PaymentRecord])->create();

    expect(osPay($setup, actor: $clerk)->refusal)->toBe(PaymentResult::REFUSAL_METHOD_NOT_AVAILABLE)
        ->and(Payment::query()->count())->toBe(0);
});

test('an Old system payment is refused once the window has closed, and the options leave the method out', function () {
    $setup = osSetup();
    $setup['property']->settings->update(['migration_window_days' => 3]);

    expect(osPay($setup)->refusal)->toBe(PaymentResult::REFUSAL_METHOD_NOT_AVAILABLE)
        ->and(Payment::query()->count())->toBe(0);
});

test('the window decides, not the active flag: an active Old system method is still refused after the window', function () {
    $setup = osSetup();
    $setup['old']->update(['active' => true]);
    $setup['property']->settings->update(['migration_window_days' => 0]);

    expect(osPay($setup)->refusal)->toBe(PaymentResult::REFUSAL_METHOD_NOT_AVAILABLE);
});

test('a normal payment still gets a receipt number', function () {
    $setup = osSetup();

    $result = osPay($setup, ['serviceDate' => null, 'reference' => null], method: $setup['cash']);

    expect($result->isRefused())->toBeFalse()
        ->and($result->payment->receipt_number)->not->toBeNull();
});

test('the date and the reference of an Old system payment are required, and the date never passes the business date', function () {
    $setup = osSetup();

    expect(osPay($setup, ['serviceDate' => null])->refusal)->toBe(PaymentResult::REFUSAL_SERVICE_DATE_INVALID)
        ->and(osPay($setup, ['serviceDate' => '2026-10-14'])->refusal)->toBe(PaymentResult::REFUSAL_SERVICE_DATE_INVALID)
        ->and(osPay($setup, ['serviceDate' => '2026-02-30'])->refusal)->toBe(PaymentResult::REFUSAL_SERVICE_DATE_INVALID)
        ->and(osPay($setup, ['reference' => null])->refusal)->toBe(PaymentResult::REFUSAL_REFERENCE_REQUIRED)
        ->and(osPay($setup, ['reference' => '  '])->refusal)->toBe(PaymentResult::REFUSAL_REFERENCE_REQUIRED)
        ->and(osPay($setup, ['serviceDate' => '2026-10-13'])->isRefused())->toBeFalse()
        ->and(osPay($setup, ['serviceDate' => '2019-01-05'])->isRefused())->toBeFalse();
});

test('other methods keep the service date range', function () {
    $setup = osSetup();

    expect(osPay($setup, ['serviceDate' => '2026-09-01', 'reference' => null], method: $setup['cash'])->refusal)->toBe(PaymentResult::REFUSAL_SERVICE_DATE_INVALID);
});

test('an Old system payment takes no surcharge even when the method row has one', function () {
    $setup = osSetup();

    $result = osPay($setup);

    expect($result->payment->amount)->toBe('100.00')
        ->and($result->lines)->toHaveCount(1)
        ->and(app(FolioBalance::class)->forFolio($setup['folio']->fresh()))->toBe('-100.00');
});

test('an Old system payment can be an advance or a security deposit with the usual placement rules', function () {
    $setup = osSetup();

    expect(osPay($setup, ['purpose' => PaymentPurpose::SecurityDeposit])->refusal)->toBe(PaymentResult::REFUSAL_DEPOSIT_ON_UNIT_ONLY)
        ->and(osPay($setup, ['purpose' => PaymentPurpose::Advance])->payment->purpose)->toBe(PaymentPurpose::Advance);
});

test('refunding an Old system payment works and the refund carries no receipt number', function () {
    $setup = osSetup();
    $payment = osPay($setup)->payment;

    $refund = app(RefundPayment::class)($setup['user'], $payment, '40.00', 'typo', fake()->uuid());

    expect($refund->isRefused())->toBeFalse()
        ->and($refund->payment->refund_of_id)->toBe($payment->id)
        ->and($refund->payment->receipt_number)->toBeNull()
        ->and($refund->payment->payment_method_id)->toBe($setup['old']->id);

    $statement = app(FolioPaymentRows::class)->for($setup['property'], collect([$setup['folio']->id => $setup['folio']->load('payerCompany')]), null);

    expect($statement[1])->toBe('60.00');
});

test('the folio invoice and booking summary rows print Paid in old system and no receipt number', function () {
    $setup = osSetup();
    osPay($setup);
    osPay($setup, ['serviceDate' => null, 'reference' => null], method: $setup['cash']);

    [$rows] = app(FolioPaymentRows::class)->for($setup['property'], collect([$setup['folio']->id => $setup['folio']->load('payerCompany')]), null);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->text)->toBe('Paid in old system · OR-1203')
        ->and($rows[0]->receiptNumber)->toBeNull()
        ->and($rows[1]->receiptNumber)->not->toBeNull();
});

test('the folio tab shows the line as Paid in old system and offers no receipt', function () {
    Inertia\Inertia::disableSsr();

    $setup = osSetup();
    $payment = osPay($setup)->payment;

    $this->actingAs($setup['user'])
        ->get(route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'folio' => $setup['folio']->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reservations/show')
            ->where('folio.rows.0.description', 'Paid in old system · OR-1203')
            ->where('folio.rows.0.hasReceipt', false));

    $this->actingAs($setup['user'])
        ->get(route('reservations.folios.payments.receipt', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'folio' => $setup['folio']->id, 'payment' => $payment->id]))
        ->assertNotFound();
});

test('the Record payment options offer Old system only inside the window and with folio.manual', function () {
    $setup = osSetup();
    $url = route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]);
    $this->actingAs($setup['user'])->get($url)->assertOk();
    $headers = fn (): array => ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'reservations/show', 'X-Inertia-Partial-Data' => 'paymentOptions', 'X-Inertia-Version' => Inertia\Inertia::getVersion()];

    $this->actingAs($setup['user'])->get($url, $headers())->assertOk()
        ->assertJsonCount(2, 'props.paymentOptions.methods')
        ->assertJsonPath('props.paymentOptions.methods.1.isOldSystem', true)
        ->assertJsonPath('props.paymentOptions.businessDate', '2026-10-13');

    $clerk = User::factory()->for($setup['property'])->withPermissions([PermissionEnum::PaymentRecord, PermissionEnum::ReservationsView])->create();
    $this->actingAs($clerk)->get($url, $headers())
        ->assertJsonCount(1, 'props.paymentOptions.methods');

    $setup['property']->settings->update(['migration_window_days' => 0]);
    $this->actingAs($setup['user'])->get($url, $headers())
        ->assertJsonCount(1, 'props.paymentOptions.methods');
});

test('the Record payment form posts an Old system payment with its date', function () {
    $setup = osSetup();
    $url = route('reservations.folios.payments.store', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'folio' => $setup['folio']->id]);

    $this->actingAs($setup['user'])->post($url, [
        'payment_method_id' => $setup['old']->id,
        'purpose' => 'settlement',
        'amount' => '50.00',
        'reference' => 'OR-1203',
        'service_date' => '2026-08-20',
        'idempotency_key' => fake()->uuid(),
    ])->assertSessionHasNoErrors();

    $this->actingAs($setup['user'])->post($url, [
        'payment_method_id' => $setup['old']->id,
        'purpose' => 'settlement',
        'amount' => '50.00',
        'reference' => 'OR-1204',
        'idempotency_key' => fake()->uuid(),
    ])->assertSessionHasErrors('service_date');

    expect(Payment::query()->count())->toBe(1)
        ->and(Payment::query()->first()->folioLine->service_date->toDateString())->toBe('2026-08-20');
});

test('the manual folio page lists Old system and its preview skips the date range', function () {
    $setup = osSetup();
    $row = ManualRowData::from([
        'key' => fake()->uuid(),
        'type' => ManualRowType::Payment,
        'serviceDate' => '2026-08-20',
        'folioId' => $setup['folio']->id,
        'amount' => '30.00',
        'paymentMethodId' => $setup['old']->id,
        'reference' => 'OR-1203',
    ]);
    $preview = app(ManualFolioPreview::class);

    $ok = $preview->preview($setup['user'], $setup['property']->fresh(['settings']), $setup['reservation'], $setup['folio'], [$row]);
    $noReference = $preview->preview($setup['user'], $setup['property']->fresh(['settings']), $setup['reservation'], $setup['folio'], [ManualRowData::from([...$row->toArray(), 'reference' => ''])]);
    $future = $preview->preview($setup['user'], $setup['property']->fresh(['settings']), $setup['reservation'], $setup['folio'], [ManualRowData::from([...$row->toArray(), 'serviceDate' => '2026-10-14'])]);

    expect($ok->rows[0]->refusal)->toBeNull()
        ->and($ok->rows[0]->total)->toBe('-30.00')
        ->and($noReference->rows[0]->refusal)->not->toBeNull()
        ->and($future->rows[0]->refusal)->not->toBeNull();

    $this->actingAs($setup['user'])
        ->get(route('reservations.folios.manual', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'folio' => $setup['folio']->id]))
        ->assertInertia(fn (Assert $page) => $page->has('manual.paymentMethods', 2)->where('manual.paymentMethods.1.isOldSystem', true));
});

test('the seeded transaction code keeps the OldSystem category', function () {
    $setup = osSetup();

    expect($setup['old']->transactionCode->category)->toBe(TransactionCategory::OldSystem);
});
