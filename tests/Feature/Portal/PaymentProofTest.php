<?php

use App\Data\Reservations\PaymentScheduleData;
use App\Enums\FolioStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentProofStatus;
use App\Enums\ReservationStatus;
use App\Enums\SystemTransactionCode;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PaymentProof;
use App\Models\PropertyBankAccount;
use App\Models\Reservation;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\CityLedger\StatementBuilder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/PortalBookingSetup.php';

/**
 * A portal booking of an agent that pays per booking, so it has a payment schedule.
 *
 * @return array{0: array<string, mixed>, 1: Reservation}
 */
function proofBooking(): array
{
    Storage::fake('local');
    $setup = portalBookingSetup();
    TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $setup['property']->id]);
    $setup['company']->update(['direct_bill_enabled' => false]);
    $reservation = portalMakeBooking($setup, ['expected_total' => '200.00']);

    return [$setup, $reservation->refresh()];
}

function proofUpload(array $setup, Reservation $reservation, User $user, array $body = []): TestResponse
{
    return test()->actingAs($user)->post(route('portal.bookings.payment-proofs.store', ['property' => $setup['property'], 'reservation' => $reservation->id]), [
        'file' => UploadedFile::fake()->image('transfer.jpg'),
        'amount' => '200.00',
        'reference' => 'MBB-778812',
        ...$body,
    ]);
}

test('an agent sends a payment proof on its booking, and the schedule shows it as received', function () {
    [$setup, $reservation] = proofBooking();

    proofUpload($setup, $reservation, $setup['portalUser'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('portal.bookings.payments', ['property' => $setup['property'], 'reservation' => $reservation->id]));

    $proof = PaymentProof::query()->sole();
    expect($proof->status)->toBe(PaymentProofStatus::Submitted)
        ->and($proof->amount)->toBe('200.00')
        ->and($proof->reference)->toBe('MBB-778812')
        ->and($proof->getFirstMedia(PaymentProof::FILE))->not->toBeNull()
        ->and(Activity::query()->where('event', 'payment_proof.uploaded')->count())->toBe(1);

    $this->actingAs($setup['portalUser'])->get(route('portal.bookings.payments', ['property' => $setup['property'], 'reservation' => $reservation->id]))
        ->assertInertia(fn (Assert $page) => $page->where('paymentSchedule.stages.0.status', 'proof')->where('paymentProofs.0.reference', 'MBB-778812'));
});

test('another company cannot send a proof for the booking or open its file', function () {
    [$setup, $reservation] = proofBooking();
    proofUpload($setup, $reservation, $setup['portalUser']);
    $stranger = portalUser();
    portalLinkFor($stranger, $setup['property'], companyName: 'Other Travel');

    proofUpload($setup, $reservation, $stranger)->assertNotFound();
    $this->actingAs($stranger)->get(route('portal.bookings.payment-proofs.file', ['property' => $setup['property'], 'reservation' => $reservation->id, 'paymentProof' => PaymentProof::query()->sole()->id]))->assertNotFound();

    expect(PaymentProof::query()->count())->toBe(1);
});

test('a proof is an image or a PDF with an amount', function () {
    [$setup, $reservation] = proofBooking();

    proofUpload($setup, $reservation, $setup['portalUser'], ['file' => UploadedFile::fake()->create('run.exe', 10, 'application/x-msdownload')])->assertSessionHasErrors('file');
    proofUpload($setup, $reservation, $setup['portalUser'], ['amount' => '0'])->assertSessionHasErrors('amount');

    expect(PaymentProof::query()->count())->toBe(0);
});

test('staff accept a proof, which records the payment with its reference, once', function () {
    [$setup, $reservation] = proofBooking();
    proofUpload($setup, $reservation, $setup['portalUser']);
    $proof = PaymentProof::query()->sole();
    $method = PaymentMethod::factory()->create(['property_id' => $setup['property']->id, 'type' => PaymentMethodType::BankTransfer, 'requires_reference' => false]);
    $url = route('reservations.payment-proofs.accept', [$setup['property']->code, $reservation, $proof]);

    $this->actingAs($setup['user'])->post($url, ['payment_method_id' => $method->id, 'amount' => '200'])->assertSessionHasNoErrors();
    $this->post($url, ['payment_method_id' => $method->id, 'amount' => '200'])->assertSessionHasErrors('amount');

    $payment = Payment::query()->sole();
    expect($proof->refresh()->status)->toBe(PaymentProofStatus::Accepted)
        ->and($proof->payment_id)->toBe($payment->id)
        ->and($payment->amount)->toBe('200.00')
        ->and($payment->reference)->toBe('MBB-778812');

    $this->get(route('reservations.show', [$setup['property']->code, $reservation]))
        ->assertInertia(fn (Assert $page) => $page->where('paymentProofs.0.status', 'accepted')->where('paymentSchedule.stages.0.status', 'paid'));
});

test('staff reject a proof with a reason the agent sees, and need payment.record', function () {
    [$setup, $reservation] = proofBooking();
    proofUpload($setup, $reservation, $setup['portalUser']);
    $proof = PaymentProof::query()->sole();
    $viewer = User::factory()->for($setup['property'])->withPermissions(['reservations.view'])->create();
    $url = route('reservations.payment-proofs.reject', [$setup['property']->code, $reservation, $proof]);

    $this->actingAs($viewer)->post($url, ['reason' => 'Not in our account'])->assertForbidden();
    $this->actingAs($setup['user'])->post($url, ['reason' => 'Not in our account yet'])->assertSessionHasNoErrors();

    expect($proof->refresh()->status)->toBe(PaymentProofStatus::Rejected)
        ->and($proof->reject_reason)->toBe('Not in our account yet')
        ->and(Payment::query()->count())->toBe(0);

    $this->actingAs($setup['portalUser'])->get(route('portal.bookings.payments', ['property' => $setup['property'], 'reservation' => $reservation->id]))
        ->assertInertia(fn (Assert $page) => $page->where('paymentProofs.0.rejectReason', 'Not in our account yet')->where('paymentSchedule.stages.0.status', 'due'));
});

test('after check-out an agent that still owes can send a proof, and the money shows on the schedule and the statement', function () {
    [$setup, $reservation] = proofBooking();
    $reservation->forceFill(['status' => ReservationStatus::Completed])->save();

    proofUpload($setup, $reservation, $setup['portalUser'], ['amount' => '80.00'])->assertSessionHasNoErrors();

    $proof = PaymentProof::query()->sole();
    $method = PaymentMethod::factory()->create(['property_id' => $setup['property']->id, 'type' => PaymentMethodType::BankTransfer]);

    test()->actingAs($setup['user'])
        ->post(route('reservations.payment-proofs.accept', [$setup['property'], $reservation, $proof]), ['payment_method_id' => $method->id, 'amount' => '80.00'])
        ->assertSessionHasNoErrors();

    $schedule = PaymentScheduleData::forReservation($reservation->fresh());
    $statement = app(StatementBuilder::class)->build($setup['company']->fresh(), now()->toImmutable()->subDays(5), now()->toImmutable());

    expect($schedule->paid)->toBe('80.00')
        ->and($schedule->left)->toBe('120.00')
        ->and($statement->bookingPayments)->toHaveCount(1)
        ->and($statement->bookingPayments[0]->booking)->toBe($reservation->reference)
        ->and($statement->bookingPayments[0]->reference)->toBe('MBB-778812')
        ->and($statement->bookingPayments[0]->amount)->toBe('80.00')
        ->and($statement->closingBalance)->toBe('0.00');
});

test('a proof is refused once the stay ended and the company folio is closed', function () {
    [$setup, $reservation] = proofBooking();
    $reservation->forceFill(['status' => ReservationStatus::Completed])->save();
    $reservation->folios()->whereNotNull('payer_company_id')->update(['status' => FolioStatus::Closed, 'closed_at' => now(), 'closed_by' => $setup['user']->id]);

    proofUpload($setup, $reservation, $setup['portalUser'])->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This booking is closed. Contact the property.']);

    expect(PaymentProof::query()->count())->toBe(0);
});

/**
 * @param  array<string, mixed>  $setup
 */
function proofPaymentsTab(array $setup, Reservation $reservation): TestResponse
{
    return test()->actingAs($setup['portalUser'])->get(route('portal.bookings.payments', ['property' => $setup['property'], 'reservation' => $reservation->id]));
}

test('an agent without a schedule sees how to pay and sends a proof naming the account it paid into', function () {
    Storage::fake('local');
    $setup = portalBookingSetup();
    TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $setup['property']->id]);
    $setup['company']->update(['direct_bill_enabled' => false, 'payment_schedule_enabled' => false]);
    $reservation = portalMakeBooking($setup, ['expected_total' => '200.00'])->refresh();
    $setup['property']->update(['contact_phone' => '+60 88 123 456']);
    $maybank = PropertyBankAccount::factory()->for($setup['property'])->create(['bank_name' => 'Maybank', 'account_number' => '5123 4567 8901', 'position' => 1]);
    $cimb = PropertyBankAccount::factory()->for($setup['property'])->create(['bank_name' => 'CIMB Bank', 'position' => 2]);
    PropertyBankAccount::factory()->for($setup['property'])->inactive()->create();

    $total = proofPaymentsTab($setup, $reservation)->viewData('page')['props']['agentBill']['agentTotal'];

    expect((float) $total)->toBeGreaterThan(0);
    proofPaymentsTab($setup, $reservation)->assertInertia(fn (Assert $page) => $page
        ->where('paymentSchedule', null)
        ->where('howToPay.billedLater', false)
        ->where('howToPay.canSendProof', true)
        ->where('howToPay.paid', '0.00')
        ->where('howToPay.leftToPay', $total)
        ->where('howToPay.bankAccounts', fn ($accounts) => collect($accounts)->pluck('id')->all() === [$maybank->id, $cimb->id])
        ->where('howToPay.contactPhone', '+60 88 123 456'));

    proofUpload($setup, $reservation, $setup['portalUser'], ['bank_account_id' => $maybank->id])->assertSessionHasNoErrors();

    expect(PaymentProof::query()->sole()->bank_account_id)->toBe($maybank->id);

    proofPaymentsTab($setup, $reservation)->assertInertia(fn (Assert $page) => $page->where('paymentProofs.0.paidInto', 'Maybank · 5123 4567 8901'));
    $this->actingAs($setup['user'])->get(route('reservations.show', [$setup['property']->code, $reservation]))
        ->assertInertia(fn (Assert $page) => $page->where('paymentProofs.0.paidInto', 'Maybank · 5123 4567 8901'));
});

test('Paid into is needed with two accounts, filled in with one, and must be an available account of this property', function () {
    [$setup, $reservation] = proofBooking();
    $first = PropertyBankAccount::factory()->for($setup['property'])->create();
    $off = PropertyBankAccount::factory()->for($setup['property'])->inactive()->create();
    $foreign = PropertyBankAccount::factory()->create();

    proofUpload($setup, $reservation, $setup['portalUser'])->assertSessionHasNoErrors();
    expect(PaymentProof::query()->sole()->bank_account_id)->toBe($first->id);

    PropertyBankAccount::factory()->for($setup['property'])->create();

    proofUpload($setup, $reservation, $setup['portalUser'])->assertSessionHasErrors(['bank_account_id' => 'Choose the account you paid into.']);
    proofUpload($setup, $reservation, $setup['portalUser'], ['bank_account_id' => $off->id])->assertSessionHasErrors('bank_account_id');
    proofUpload($setup, $reservation, $setup['portalUser'], ['bank_account_id' => $foreign->id])->assertSessionHasErrors('bank_account_id');

    expect(PaymentProof::query()->count())->toBe(1);
});

test('a proof without any bank account names none', function () {
    [$setup, $reservation] = proofBooking();

    proofUpload($setup, $reservation, $setup['portalUser'])->assertSessionHasNoErrors();

    expect(PaymentProof::query()->sole()->bank_account_id)->toBeNull();
    proofPaymentsTab($setup, $reservation)->assertInertia(fn (Assert $page) => $page->where('howToPay.bankAccounts', [])->where('paymentProofs.0.paidInto', null));
});

test('a direct-billing company is told to pay its invoice and cannot send a proof on the booking', function () {
    [$setup, $reservation] = proofBooking();
    $setup['company']->update(['direct_bill_enabled' => true]);

    proofPaymentsTab($setup, $reservation)->assertInertia(fn (Assert $page) => $page->where('howToPay.billedLater', true)->where('howToPay.canSendProof', false));
    proofUpload($setup, $reservation, $setup['portalUser'])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Your company is billed after the stay. Pay the invoice from Statements.']);

    expect(PaymentProof::query()->count())->toBe(0);
});

test('the form is hidden once nothing is left to pay, and for a suspended company', function () {
    [$setup, $reservation] = proofBooking();
    proofUpload($setup, $reservation, $setup['portalUser']);
    $method = PaymentMethod::factory()->create(['property_id' => $setup['property']->id, 'type' => PaymentMethodType::BankTransfer, 'requires_reference' => false]);
    $total = proofPaymentsTab($setup, $reservation)->viewData('page')['props']['agentBill']['agentTotal'];

    $this->actingAs($setup['user'])
        ->post(route('reservations.payment-proofs.accept', [$setup['property']->code, $reservation, PaymentProof::query()->sole()]), ['payment_method_id' => $method->id, 'amount' => $total])
        ->assertSessionHasNoErrors();

    proofPaymentsTab($setup, $reservation)->assertInertia(fn (Assert $page) => $page->where('howToPay.leftToPay', '0.00')->where('howToPay.paid', $total)->where('howToPay.canSendProof', false));

    [$setup, $reservation] = proofBooking();
    $setup['company']->update(['status' => 'suspended']);

    proofPaymentsTab($setup, $reservation)->assertInertia(fn (Assert $page) => $page->where('howToPay.canSendProof', false));
});
