<?php

use App\Actions\Channels\IngestChannelBooking;
use App\Actions\Channels\PostOtaCollectedPayment;
use App\Actions\Payments\RecordPayment;
use App\Data\Payments\NewPaymentData;
use App\Enums\ChannelAlertEvent;
use App\Enums\FolioType;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Enums\SystemTransactionCode;
use App\Models\BusinessDate;
use App\Models\ChannelAlert;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Reservation;
use App\Models\TransactionCode;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/ChannelBooking.php';

/** The channel booking setup with an active OTA collected method. */
function otaPaymentSetup(): array
{
    $setup = channelBookingSetup();
    fakeChannexAck();
    $method = PaymentMethod::factory()->create(['property_id' => $setup['property']->id, 'name' => 'OTA collected', 'type' => PaymentMethodType::OtaCollected, 'requires_reference' => false]);

    return [...$setup, 'method' => $method];
}

function ingestOta(array $setup, array $attributes = []): void
{
    app(IngestChannelBooking::class)(storeChannelRevision($setup, ['payment_collect' => 'ota', ...$attributes]));
}

test('an OTA collected booking posts the booking amount as an OTA collected payment with no receipt', function () {
    $setup = otaPaymentSetup();

    ingestOta($setup, ['payment_type' => 'bank_transfer']);

    $payment = Payment::query()->with(['folio', 'folioLine'])->sole();
    expect($payment->payment_method_id)->toBe($setup['method']->id)
        ->and($payment->amount)->toBe('200.00')
        ->and($payment->purpose)->toBe(PaymentPurpose::Advance)
        ->and($payment->reference)->toBe('Bank transfer')
        ->and($payment->receipt_number)->toBeNull()
        ->and($payment->folio->type)->toBe(FolioType::Unit)
        ->and($payment->folio->reservation_id)->toBe(Reservation::query()->sole()->id)
        ->and($payment->folioLine->amount)->toBe('-200.00');
});

test('a virtual card booking names the card and the day it can be charged', function () {
    $setup = otaPaymentSetup();

    ingestOta($setup, [
        'payment_type' => 'credit_card',
        'guarantee' => ['card_type' => 'MC', 'is_virtual' => true, 'meta' => ['virtual_card_effective_date' => '2026-10-20']],
    ]);

    expect(Payment::query()->sole()->reference)->toBe('Virtual card · charge from 2026-10-20');
});

test('a booking the property collects posts nothing', function (?string $collect) {
    $setup = otaPaymentSetup();

    ingestOta($setup, ['payment_collect' => $collect]);

    expect(Reservation::query()->count())->toBe(1)
        ->and(Payment::query()->count())->toBe(0);
})->with(['property' => ['property'], 'not given' => [null]]);

test('a two room booking whose Room charges stay on each unit posts each room amount on its own folio', function () {
    $setup = otaPaymentSetup();

    ingestOta($setup, [
        'amount' => '450.00',
        'rooms' => [channelRevisionRoom(), channelRevisionRoom(['amount' => '210.00'])],
    ]);

    $payments = Payment::query()->with('folio')->orderBy('id')->get();
    expect($payments->map(fn (Payment $payment): string => $payment->amount)->all())->toBe(['240.00', '210.00'])
        ->and($payments->map(fn (Payment $payment): FolioType => $payment->folio->type)->all())->toBe([FolioType::Unit, FolioType::Unit])
        ->and($payments[0]->folio_id)->not->toBe($payments[1]->folio_id);
});

test('a two room booking whose Room charges go to the Master folio posts one payment there', function () {
    $setup = otaPaymentSetup();
    TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $setup['property']->id]);

    ingestOta($setup, ['amount' => '410.00', 'rooms' => [channelRevisionRoom(), channelRevisionRoom(['amount' => '210.00'])]]);

    $payment = Payment::query()->with('folio')->sole();
    expect($payment->amount)->toBe('410.00')
        ->and($payment->folio->type)->toBe(FolioType::Master);
});

test('a modified booking posts only the higher amount, and a lower amount posts nothing', function () {
    $setup = otaPaymentSetup();
    ingestOta($setup);

    ingestOta($setup, ['status' => 'modified', 'amount' => '250.00', 'rooms' => [channelRevisionRoom(['amount' => '250.00'])]]);
    ingestOta($setup, ['status' => 'modified', 'amount' => '180.00', 'rooms' => [channelRevisionRoom(['amount' => '180.00'])]]);

    expect(Payment::query()->orderBy('id')->pluck('amount')->all())->toBe(['200.00', '50.00']);
});

test('money staff already recorded as OTA collected is not posted again', function () {
    $setup = otaPaymentSetup();
    ingestOta($setup, ['payment_collect' => 'property']);
    $unit = Reservation::query()->sole()->units()->sole();
    app(RecordPayment::class)($setup['user'], Reservation::query()->sole(), null, $unit, new NewPaymentData(
        paymentMethodId: $setup['method']->id,
        purpose: PaymentPurpose::Advance,
        amount: '200.00',
        reference: null,
        idempotencyKey: (string) Str::uuid(),
        allowOtaCollected: true,
    ));

    ingestOta($setup, ['status' => 'modified']);

    expect(Payment::query()->count())->toBe(1);
});

test('a revision ingested again posts its payment once', function () {
    $setup = otaPaymentSetup();
    $revision = storeChannelRevision($setup, ['payment_collect' => 'ota']);

    app(IngestChannelBooking::class)($revision);
    app(PostOtaCollectedPayment::class)($setup['user'], Reservation::query()->sole(), $revision->refresh());

    expect(Payment::query()->count())->toBe(1);
});

test('a refused payment still saves the booking and opens an alert', function () {
    $setup = otaPaymentSetup();
    BusinessDate::query()->where('property_id', $setup['property']->id)->delete();

    $revision = storeChannelRevision($setup, ['payment_collect' => 'ota']);
    $result = app(IngestChannelBooking::class)($revision);

    $alert = ChannelAlert::query()->sole();
    expect($result->ok)->toBeTrue()
        ->and($revision->refresh()->acknowledged_at)->not->toBeNull()
        ->and(Reservation::query()->count())->toBe(1)
        ->and(Payment::query()->count())->toBe(0)
        ->and($alert->event)->toBe(ChannelAlertEvent::OtaPaymentNotPosted)
        ->and($alert->external_booking_id)->toBe('booking-1')
        ->and($alert->resolved_at)->toBeNull()
        ->and($alert->payload['message'])->toContain('no_business_date');
});

test('an inactive OTA collected method is not used, and the booking alerts', function () {
    $setup = otaPaymentSetup();
    $setup['method']->update(['active' => false]);

    ingestOta($setup);

    expect(Payment::query()->count())->toBe(0)
        ->and(ChannelAlert::query()->sole()->payload['message'])->toContain('method_not_available');
});

test('a cancelled booking keeps the OTA collected payment', function () {
    $setup = otaPaymentSetup();
    ingestOta($setup);

    ingestOta($setup, ['status' => 'cancelled']);

    expect(Payment::query()->count())->toBe(1)
        ->and(Payment::query()->whereNotNull('refund_of_id')->count())->toBe(0);
});

test('the migration switches the OTA collected method on for existing properties', function () {
    $setup = otaPaymentSetup();
    $setup['method']->update(['active' => false]);
    $card = PaymentMethod::factory()->inactive()->create(['property_id' => $setup['property']->id]);

    (include database_path('migrations/2026_10_28_120000_turn_on_ota_collected_payment_methods.php'))->up();

    expect($setup['method']->refresh()->active)->toBeTrue()
        ->and($card->refresh()->active)->toBeFalse();
});
