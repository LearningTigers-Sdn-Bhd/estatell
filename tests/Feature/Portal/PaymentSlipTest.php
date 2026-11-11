<?php

use App\Enums\ArInvoiceStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentProofStatus;
use App\Models\ArInvoice;
use App\Models\ArPayment;
use App\Models\PaymentMethod;
use App\Models\PaymentProof;
use App\Models\PropertyBankAccount;
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

    slipUpload($setup)
        ->assertRedirect(route('portal.statements.slips', $setup['property']))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Payment slip sent. The property will confirm the payment.']);

    $slip = PaymentProof::query()->sole();

    expect($slip->company_id)->toBe($setup['company']->id)
        ->and($slip->reservation_id)->toBeNull()
        ->and($slip->status)->toBe(PaymentProofStatus::Submitted)
        ->and(ArPayment::query()->count())->toBe(0);
    Notification::assertSentTo($setup['user'], PaymentSlipReceived::class);

    $this->actingAs($setup['portalUser'])->get(route('portal.statements.slips', $setup['property']))
        ->assertInertia(fn (Assert $page) => $page->where('canSendSlip', true)->where('slipsWaiting', 1)->where('paymentSlips.0.status', 'submitted')->where('paymentSlips.0.reference', 'MBB-1'));
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

    $this->actingAs($setup['portalUser'])->get(route('portal.statements.slips', $setup['property']))
        ->assertInertia(fn (Assert $page) => $page->where('paymentSlips.0.status', 'accepted')->where('slipsWaiting', 0));
    $this->get(route('portal.statements', $setup['property']))
        ->assertInertia(fn (Assert $page) => $page->where('statement.closingBalance', '300.00'));
    $this->get(route('portal.statements.invoices', $setup['property']))
        ->assertInertia(fn (Assert $page) => $page->where('invoices.data.0.balance', '300.00'));
});

test('an accepted slip leaves the slip table, and its payment opens the slip', function () {
    $setup = slipSetup();
    slipUpload($setup);
    slipUpload($setup, ['reference' => 'MBB-2']);
    [$first, $second] = PaymentProof::query()->orderBy('id')->get()->all();

    $this->actingAs($setup['user'])->post(slipRoute($setup, 'accept', $first), ['payment_method_id' => $setup['method']->id, 'amount' => '200'])->assertSessionHasNoErrors();

    $this->get(route('finance.city-ledger.companies.payments', [$setup['property']->code, $setup['company']]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('paymentSlips', 1)
            ->where('paymentSlips.0.id', $second->id)
            ->where('payments.0.slipUrl', route('finance.city-ledger.companies.payment-slips.file', [$setup['property']->code, $setup['company'], $first], absolute: false)));
});

test('a slip with no invoice open stays as credit, and the summary shows balance, overdue and credit', function () {
    $setup = slipSetup();
    $setup['invoice']->update(['issued_on' => '2026-09-01', 'due_on' => '2026-09-30']);
    slipUpload($setup, ['amount' => '700.00']);

    $this->actingAs($setup['user'])->post(slipRoute($setup, 'accept', PaymentProof::query()->sole()), ['payment_method_id' => $setup['method']->id, 'amount' => '700.00'])
        ->assertSessionHasNoErrors();

    $this->actingAs($setup['portalUser'])->get(route('portal.statements', $setup['property']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.asOf', '2026-10-14')
            ->where('summary.balance', '-200.00')
            ->where('summary.overdue', '0.00')
            ->where('summary.credit', '200.00'));
    $this->get(route('portal.statements.slips', $setup['property']))
        ->assertInertia(fn (Assert $page) => $page->where('paymentSlips.0.invoiceNumber', null));
});

test('the summary counts what is past due as overdue', function () {
    $setup = slipSetup();
    $setup['invoice']->update(['issued_on' => '2026-09-01', 'due_on' => '2026-09-30']);

    $this->actingAs($setup['portalUser'])->get(route('portal.statements', $setup['property']))
        ->assertInertia(fn (Assert $page) => $page->where('summary.balance', '500.00')->where('summary.overdue', '500.00')->where('summary.credit', '0.00'));
});

test('a rejected slip records nothing and the company sees the reason', function () {
    $setup = slipSetup();
    slipUpload($setup);
    $slip = PaymentProof::query()->sole();

    $this->actingAs($setup['user'])->post(slipRoute($setup, 'reject', $slip), ['reason' => 'Not in our account'])->assertSessionHasNoErrors();
    $this->post(slipRoute($setup, 'reject', $slip), ['reason' => 'Again'])->assertSessionHasErrors('reason');

    expect(ArPayment::query()->count())->toBe(0);

    $this->actingAs($setup['portalUser'])->get(route('portal.statements.slips', $setup['property']))
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

test('the slip form shows the available accounts, and the slip saves the account the company paid into', function () {
    $setup = slipSetup();
    $maybank = PropertyBankAccount::factory()->for($setup['property'])->create(['bank_name' => 'Maybank', 'account_number' => '5123 4567 8901', 'position' => 1]);
    $cimb = PropertyBankAccount::factory()->for($setup['property'])->create(['position' => 2]);

    $this->actingAs($setup['portalUser'])->get(route('portal.statements', $setup['property']))
        ->assertInertia(fn (Assert $page) => $page->where('bankAccounts', fn ($accounts) => collect($accounts)->pluck('id')->all() === [$maybank->id, $cimb->id]));

    slipUpload($setup)->assertSessionHasErrors('bank_account_id');
    slipUpload($setup, ['bank_account_id' => $maybank->id])->assertSessionHasNoErrors();

    expect(PaymentProof::query()->sole()->bank_account_id)->toBe($maybank->id);

    $this->actingAs($setup['user'])->get(route('finance.city-ledger.companies.payments', [$setup['property']->code, $setup['company']]))
        ->assertInertia(fn (Assert $page) => $page->where('paymentSlips.0.paidInto', 'Maybank · 5123 4567 8901'));
});
