<?php

use App\Enums\ArInvoiceStatus;
use App\Enums\DefaultRoleEnum;
use App\Enums\PaymentMethodType;
use App\Models\ArAllocation;
use App\Models\ArInvoice;
use App\Models\ArPayment;
use App\Models\BusinessDate;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * A live property with an open business date, a company, a Bank transfer method and a user with the role.
 *
 * @return array{user: User, property: Property, company: Company, method: PaymentMethod}
 */
function arPagesSetup(DefaultRoleEnum $role = DefaultRoleEnum::Finance): array
{
    $user = User::factory()->withRole($role)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);

    return [
        'user' => $user,
        'property' => $property,
        'company' => Company::factory()->for($property)->create(),
        'method' => PaymentMethod::factory()->create(['property_id' => $property->id, 'type' => PaymentMethodType::BankTransfer, 'requires_reference' => false, 'name' => 'Bank transfer']),
    ];
}

function arPaymentsUrl(array $setup, string $suffix = '', ?Company $company = null): string
{
    return "/p/{$setup['property']->code}/finance/city-ledger/companies/".($company ?? $setup['company'])->id."/payments{$suffix}";
}

function arPayload(array $setup, array $overrides = []): array
{
    return ['payment_method_id' => $setup['method']->id, 'amount' => '200.00', 'idempotency_key' => fake()->uuid(), ...$overrides];
}

test('the Payments tab lists payments with what is left, the open invoices and the methods', function () {
    $setup = arPagesSetup();
    $invoice = ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'number' => 'F-1', 'amount' => '300.00']);
    $payment = ArPayment::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'payment_method_id' => $setup['method']->id, 'amount' => '500.00', 'receipt_number' => 'R-1']);
    ArAllocation::factory()->create(['ar_payment_id' => $payment->id, 'ar_invoice_id' => $invoice->id, 'amount' => '100.00']);
    ArPayment::factory()->voided()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'payment_method_id' => $setup['method']->id, 'receipt_number' => 'R-0']);
    PaymentMethod::factory()->create(['property_id' => $setup['property']->id, 'type' => PaymentMethodType::DirectBill]);
    PaymentMethod::factory()->create(['property_id' => $setup['property']->id, 'type' => PaymentMethodType::Cash, 'active' => false]);
    ArPayment::factory()->create();

    $this->actingAs($setup['user'])->get(arPaymentsUrl($setup))->assertInertia(fn (Assert $page) => $page
        ->component('finance/city-ledger/companies/show')
        ->where('tab', 'payments')
        ->has('payments', 2)
        ->where('payments.0.receiptNumber', 'R-0')
        ->where('payments.0.isVoided', true)
        ->where('payments.0.unallocated', '0.00')
        ->where('payments.0.canVoid', false)
        ->where('payments.1.receiptNumber', 'R-1')
        ->where('payments.1.unallocated', '400.00')
        ->where('payments.1.canAllocate', true)
        ->where('payments.1.canVoid', false)
        ->has('openInvoices', 1)
        ->where('openInvoices.0.balance', '200.00')
        ->has('methods', 1)
        ->where('methods.0.name', 'Bank transfer')
        ->where('canRecordPayment', true));
});

test('front desk can open the tab but not act', function () {
    $setup = arPagesSetup(DefaultRoleEnum::FrontDesk);
    ArPayment::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'payment_method_id' => $setup['method']->id]);

    $this->actingAs($setup['user'])->get(arPaymentsUrl($setup))->assertInertia(fn (Assert $page) => $page
        ->where('canRecordPayment', false)
        ->where('payments.0.canAllocate', false)
        ->where('payments.0.canVoid', false));
    $this->post(arPaymentsUrl($setup), arPayload($setup))->assertForbidden();

    expect(ArPayment::query()->count())->toBe(1);
});

test('the tab needs company.view and stays inside the property', function () {
    $setup = arPagesSetup(DefaultRoleEnum::Housekeeping);
    $foreign = Company::factory()->create();

    $this->actingAs($setup['user'])->get(arPaymentsUrl($setup))->assertForbidden();

    $finance = User::factory()->withRole(DefaultRoleEnum::Finance)->create(['property_id' => $setup['property']->id]);

    $this->actingAs($finance)->get(arPaymentsUrl($setup, '', $foreign))->assertNotFound();
});

test('the tab runs the same queries however many payments there are', function () {
    $setup = arPagesSetup();
    $this->actingAs($setup['user']);
    ArPayment::factory()->count(2)->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'payment_method_id' => $setup['method']->id]);
    ArInvoice::factory()->count(2)->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id]);
    $this->get(arPaymentsUrl($setup));

    DB::enableQueryLog();
    $this->get(arPaymentsUrl($setup));
    $few = count(DB::getQueryLog());
    ArPayment::factory()->count(8)->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'payment_method_id' => $setup['method']->id]);
    ArInvoice::factory()->count(8)->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id]);
    DB::flushQueryLog();
    $this->get(arPaymentsUrl($setup));

    expect(count(DB::getQueryLog()))->toBe($few);
});

test('a payment is recorded and matched to the oldest invoices', function () {
    $setup = arPagesSetup();
    $invoice = ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'amount' => '120.00']);

    $this->actingAs($setup['user'])->post(arPaymentsUrl($setup), arPayload($setup, ['allocate' => true, 'reference' => 'TRF-9']))
        ->assertRedirect()->assertSessionHasNoErrors();

    $payment = ArPayment::query()->sole();

    expect($payment->reference)->toBe('TRF-9')
        ->and($invoice->fresh()->status)->toBe(ArInvoiceStatus::Settled)
        ->and(ArAllocation::query()->sole()->amount)->toBe('120.00');
});

test('a refusal comes back as a field error and a repeat is not a second payment', function () {
    $setup = arPagesSetup();
    $this->actingAs($setup['user']);

    $this->post(arPaymentsUrl($setup), arPayload($setup, ['amount' => '0']))->assertSessionHasErrors('amount');
    $this->post(arPaymentsUrl($setup), arPayload($setup, ['payment_method_id' => 999999]))->assertSessionHasErrors('payment_method_id');
    $this->post(arPaymentsUrl($setup), arPayload($setup, ['received_on' => '2999-01-01']))->assertSessionHasErrors('received_on');
    $this->post(arPaymentsUrl($setup), ['amount' => '10.00'])->assertSessionHasErrors(['payment_method_id', 'idempotency_key']);

    expect(ArPayment::query()->count())->toBe(0);

    $body = arPayload($setup);
    $this->post(arPaymentsUrl($setup), $body);
    $this->post(arPaymentsUrl($setup), $body)->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Payment '.ArPayment::query()->sole()->receipt_number.' was already recorded.']);

    expect(ArPayment::query()->count())->toBe(1);
});

test('a payment is allocated from its route and a refusal is a form error', function () {
    $setup = arPagesSetup();
    $invoice = ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'amount' => '300.00']);
    $payment = ArPayment::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'payment_method_id' => $setup['method']->id, 'amount' => '200.00']);
    $url = arPaymentsUrl($setup, "/{$payment->id}/allocations");

    $this->actingAs($setup['user'])->post($url, ['allocations' => [['ar_invoice_id' => $invoice->id, 'amount' => '999.00']]])->assertSessionHasErrors('form');
    $this->post($url, ['allocations' => []])->assertSessionHasErrors('allocations');
    $this->post($url, ['allocations' => [['ar_invoice_id' => $invoice->id, 'amount' => '150.00']]])->assertRedirect()->assertSessionHasNoErrors();

    expect(ArAllocation::query()->sole()->amount)->toBe('150.00');
});

test('an allocation is reversed with a reason and the payment is then voided', function () {
    $setup = arPagesSetup();
    $invoice = ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'amount' => '100.00']);
    $payment = ArPayment::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'payment_method_id' => $setup['method']->id, 'amount' => '100.00']);
    $allocation = ArAllocation::factory()->create(['ar_payment_id' => $payment->id, 'ar_invoice_id' => $invoice->id, 'amount' => '100.00']);
    $reverse = arPaymentsUrl($setup, "/{$payment->id}/allocations/{$allocation->id}/reverse");
    $void = arPaymentsUrl($setup, "/{$payment->id}/void");

    $this->actingAs($setup['user'])->post($void)->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Reverse the allocations of this payment first.']);
    $this->post($reverse, ['reason' => ''])->assertSessionHasErrors('reason');
    $this->post($reverse, ['reason' => 'Wrong invoice'])->assertRedirect()->assertSessionHasNoErrors();

    expect($allocation->fresh()->reversed_at)->not->toBeNull();

    $this->post($void)->assertInertiaFlash('toast', ['type' => 'success', 'message' => "Payment {$payment->receipt_number} voided."]);

    expect($payment->fresh()->voided_at)->not->toBeNull()
        ->and(Activity::query()->where('event', 'ar_payment.voided')->count())->toBe(1);
});

test('the allocate, reverse and void routes need their own permissions', function () {
    $setup = arPagesSetup(DefaultRoleEnum::FrontDesk);
    $payment = ArPayment::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'payment_method_id' => $setup['method']->id]);
    $allocation = ArAllocation::factory()->create(['ar_payment_id' => $payment->id]);

    $this->actingAs($setup['user']);
    $this->post(arPaymentsUrl($setup, "/{$payment->id}/allocations"), ['allocations' => [['ar_invoice_id' => 1, 'amount' => '1']]])->assertForbidden();
    $this->post(arPaymentsUrl($setup, "/{$payment->id}/allocations/{$allocation->id}/reverse"), ['reason' => 'x'])->assertForbidden();
    $this->post(arPaymentsUrl($setup, "/{$payment->id}/void"))->assertForbidden();
});

test('a payment or allocation of another company is a 404', function () {
    $setup = arPagesSetup();
    $other = Company::factory()->for($setup['property'])->create();
    $payment = ArPayment::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $other->id, 'payment_method_id' => $setup['method']->id]);

    $this->actingAs($setup['user']);
    $this->post(arPaymentsUrl($setup, "/{$payment->id}/void"))->assertNotFound();
    $this->get(arPaymentsUrl($setup, "/{$payment->id}/receipt"))->assertNotFound();
});

test('the receipt PDF streams for a payment and a voided one', function () {
    $setup = arPagesSetup(DefaultRoleEnum::FrontDesk);
    $payment = ArPayment::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'payment_method_id' => $setup['method']->id, 'reference' => 'TRF-1', 'notes' => 'Cheque']);
    $voided = ArPayment::factory()->voided()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'payment_method_id' => $setup['method']->id]);

    $this->actingAs($setup['user']);

    foreach ([$payment, $voided] as $one) {
        $response = $this->get(arPaymentsUrl($setup, "/{$one->id}/receipt"))->assertOk();

        expect($response->headers->get('content-type'))->toContain('application/pdf');
    }
});

test('the invoice page lists its allocations and lets Finance reverse an active one', function () {
    $setup = arPagesSetup();
    $invoice = ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'amount' => '300.00']);
    $payment = ArPayment::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'payment_method_id' => $setup['method']->id, 'receipt_number' => 'R-7']);
    ArAllocation::factory()->create(['ar_payment_id' => $payment->id, 'ar_invoice_id' => $invoice->id, 'amount' => '100.00']);
    ArAllocation::factory()->reversed()->create(['ar_payment_id' => $payment->id, 'ar_invoice_id' => $invoice->id, 'amount' => '50.00']);

    $this->actingAs($setup['user'])->get("/p/{$setup['property']->code}/finance/city-ledger/invoices/{$invoice->id}")->assertInertia(fn (Assert $page) => $page
        ->where('invoice.row.balance', '200.00')
        ->has('invoice.allocations', 2)
        ->where('invoice.allocations.0.isReversed', true)
        ->where('invoice.allocations.0.canReverse', false)
        ->where('invoice.allocations.1.receiptNumber', 'R-7')
        ->where('invoice.allocations.1.canReverse', true)
        ->where('invoice.allocations.1.companyId', $setup['company']->id));
});
