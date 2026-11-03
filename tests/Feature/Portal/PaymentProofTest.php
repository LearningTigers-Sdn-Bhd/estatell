<?php

use App\Enums\PaymentMethodType;
use App\Enums\PaymentProofStatus;
use App\Enums\SystemTransactionCode;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PaymentProof;
use App\Models\Reservation;
use App\Models\TransactionCode;
use App\Models\User;
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

    proofUpload($setup, $reservation, $setup['portalUser'])->assertSessionHasNoErrors();

    $proof = PaymentProof::query()->sole();
    expect($proof->status)->toBe(PaymentProofStatus::Submitted)
        ->and($proof->amount)->toBe('200.00')
        ->and($proof->reference)->toBe('MBB-778812')
        ->and($proof->getFirstMedia(PaymentProof::FILE))->not->toBeNull()
        ->and(Activity::query()->where('event', 'payment_proof.uploaded')->count())->toBe(1);

    $this->actingAs($setup['portalUser'])->get(route('portal.bookings.show', ['property' => $setup['property'], 'reservation' => $reservation->id]))
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

    $this->actingAs($setup['portalUser'])->get(route('portal.bookings.show', ['property' => $setup['property'], 'reservation' => $reservation->id]))
        ->assertInertia(fn (Assert $page) => $page->where('paymentProofs.0.rejectReason', 'Not in our account yet')->where('paymentSchedule.stages.0.status', 'due'));
});
