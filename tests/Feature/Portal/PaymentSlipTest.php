<?php

use App\Enums\ArInvoiceStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentProofStatus;
use App\Models\ArInvoice;
use App\Models\ArPayment;
use App\Models\PaymentMethod;
use App\Models\PaymentProof;
use App\Models\User;
use App\Notifications\PaymentSlipReceived;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/PortalBookingSetup.php';

/**
 * A company on direct billing with one open invoice of 500.00.
 *
 * @return array<string, mixed>
 */
function slipSetup(): array
{
    Storage::fake('local');
    Notification::fake();
    $setup = portalBookingSetup();
    $setup['company']->update(['direct_bill_enabled' => true]);
    $setup['invoice'] = ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'amount' => '500.00', 'status' => ArInvoiceStatus::Open]);
    $setup['method'] = PaymentMethod::factory()->create(['property_id' => $setup['property']->id, 'type' => PaymentMethodType::BankTransfer, 'requires_reference' => false]);

    return $setup;
}

/**
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $body
 */
function slipUpload(array $setup, array $body = [], ?User $as = null): TestResponse
{
    return test()->actingAs($as ?? $setup['portalUser'])->post(route('portal.statements.payment-slips.store', ['property' => $setup['property']]), [
        'file' => UploadedFile::fake()->image('slip.jpg'),
        'amount' => '200.00',
        'reference' => 'MBB-1',
        ...$body,
    ]);
}

/**
 * @param  array<string, mixed>  $setup
 */
function slipRoute(array $setup, string $action, PaymentProof $slip): string
{
    return route("finance.city-ledger.companies.payment-slips.{$action}", [$setup['property']->code, $setup['company'], $slip]);
}

test('a company on credit terms sends a slip: it is told, staff are told, and it is not a payment yet', function () {
    $setup = slipSetup();

    slipUpload($setup)->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Payment slip sent. The property will confirm the payment.']);

    $slip = PaymentProof::query()->sole();

    expect($slip->company_id)->toBe($setup['company']->id)
        ->and($slip->reservation_id)->toBeNull()
        ->and($slip->status)->toBe(PaymentProofStatus::Submitted)
        ->and(ArPayment::query()->count())->toBe(0);
    Notification::assertSentTo($setup['user'], PaymentSlipReceived::class);

    $this->actingAs($setup['portalUser'])->get(route('portal.statements', $setup['property']))
        ->assertInertia(fn (Assert $page) => $page->where('canSendSlip', true)->where('paymentSlips.0.status', 'submitted')->where('paymentSlips.0.reference', 'MBB-1'));
});

test('accepting a slip records a city ledger payment against the oldest invoice, once', function () {
    $setup = slipSetup();
    slipUpload($setup);
    $slip = PaymentProof::query()->sole();
    $accept = slipRoute($setup, 'accept', $slip);

    $this->actingAs($setup['user'])->post($accept, ['payment_method_id' => $setup['method']->id, 'amount' => '200'])
        ->assertSessionHasNoErrors()->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Payment recorded from the slip.']);
    $this->post($accept, ['payment_method_id' => $setup['method']->id, 'amount' => '200'])->assertSessionHasErrors('amount');

    $payment = ArPayment::query()->sole();

    expect($payment->amount)->toBe('200.00')
        ->and($payment->reference)->toBe('MBB-1')
        ->and($slip->refresh()->status)->toBe(PaymentProofStatus::Accepted)
        ->and($slip->ar_payment_id)->toBe($payment->id);

    $this->actingAs($setup['portalUser'])->get(route('portal.statements', $setup['property']))
        ->assertInertia(fn (Assert $page) => $page->where('paymentSlips.0.status', 'accepted')->where('statement.closingBalance', '300.00')->where('invoices.data.0.balance', '300.00'));
});

test('a rejected slip records nothing and the company sees the reason', function () {
    $setup = slipSetup();
    slipUpload($setup);
    $slip = PaymentProof::query()->sole();

    $this->actingAs($setup['user'])->post(slipRoute($setup, 'reject', $slip), ['reason' => 'Not in our account'])->assertSessionHasNoErrors();
    $this->post(slipRoute($setup, 'reject', $slip), ['reason' => 'Again'])->assertSessionHasErrors('reason');

    expect(ArPayment::query()->count())->toBe(0);

    $this->actingAs($setup['portalUser'])->get(route('portal.statements', $setup['property']))
        ->assertInertia(fn (Assert $page) => $page->where('paymentSlips.0.status', 'rejected')->where('paymentSlips.0.rejectReason', 'Not in our account'));
});

test('a slip is an image or PDF with an amount, and a failure says why', function () {
    $setup = slipSetup();

    slipUpload($setup, ['file' => UploadedFile::fake()->create('virus.exe', 10)])->assertSessionHasErrors('file');
    slipUpload($setup, ['amount' => '0'])->assertSessionHasErrors('amount');

    expect(PaymentProof::query()->count())->toBe(0);
});

test('a company that pays per booking is sent to the booking instead', function () {
    $setup = slipSetup();
    $setup['company']->update(['direct_bill_enabled' => false]);
    $setup['invoice']->update(['status' => ArInvoiceStatus::Settled]);

    slipUpload($setup)->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Your company pays per booking. Send the proof on the booking.']);

    expect(PaymentProof::query()->count())->toBe(0);
});

test('another company cannot open a slip, and only staff with city-ledger.payment can check one', function () {
    $setup = slipSetup();
    slipUpload($setup);
    $slip = PaymentProof::query()->sole();
    $other = portalUser();
    portalLinkFor($other, $setup['property'], companyName: 'Other Agency');

    $this->actingAs($other)->get(route('portal.statements.payment-slips.file', [$setup['property'], $slip]))->assertNotFound();

    $staff = User::factory()->for($setup['property'])->withPermissions(['reservations.view'])->create();
    $this->actingAs($staff)->post(slipRoute($setup, 'accept', $slip), ['payment_method_id' => $setup['method']->id, 'amount' => '200'])->assertForbidden();

    expect($slip->refresh()->status)->toBe(PaymentProofStatus::Submitted);
});
