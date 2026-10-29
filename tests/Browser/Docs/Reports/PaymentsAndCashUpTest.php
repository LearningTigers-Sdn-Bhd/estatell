<?php

use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\RefundPayment;
use App\Data\Payments\NewPaymentData;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Browser\Docs\Companies\ArInvoicesAndPayments\ArFixture;

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

/**
 * Give the cash-up more than one cashier: Joseph takes cash and card on S11 and a city ledger payment comes in, then the
 * Owner refunds part of the cash. Kinabalu's audits already hold the Owner's payments of the week.
 */
function cashUpActivity(Property $property, User $owner): void
{
    $joseph = User::query()->where('email', 'frontdesk@kinabalu-hostel.test')->firstOrFail();
    $reservation = test()->scenario('S11');
    $method = fn (PaymentMethodType $type): PaymentMethod => PaymentMethod::query()->where('property_id', $property->id)->where('type', $type)->where('active', true)->firstOrFail();

    $cash = app(RecordPayment::class)($joseph, $reservation, null, $reservation->units()->firstOrFail(), new NewPaymentData($method(PaymentMethodType::Cash)->id, PaymentPurpose::Settlement, '200.00', null, (string) Str::uuid()));
    app(RecordPayment::class)($joseph, $reservation, null, $reservation->units()->firstOrFail(), new NewPaymentData($method(PaymentMethodType::Card)->id, PaymentPurpose::Settlement, '120.00', 'Visa ending 4242', (string) Str::uuid()));

    ArFixture::pay(ArFixture::company($property), PaymentMethodType::BankTransfer, '300.00', 'MBB-88231');

    app(RefundPayment::class)($owner, Payment::query()->findOrFail($cash->payment->id), '50.00', null, (string) Str::uuid());
}

test('payments by method', function () {
    cashUpActivity($this->property, auth()->user());

    $page = $this->open(route('reports.payments', ['property' => $this->property, 'from' => '2026-10-08', 'to' => '2026-10-14']), height: 1100)
        ->wait(1);

    $this->shoot($page, 'payments-by-method',
        badges: ['By method', 'By staff', 'All staff', 'Refunds only', 'Status', 'Total by method'],
    );
});

test('payments by staff', function () {
    cashUpActivity($this->property, auth()->user());

    $page = $this->open(route('reports.payments', ['property' => $this->property, 'from' => '2026-10-08', 'to' => '2026-10-14', 'group' => 'staff']), height: 1100)
        ->wait(1);

    $this->shoot($page, 'payments-by-staff',
        badges: ['By staff', 'Cash-up by staff'],
    );
});
