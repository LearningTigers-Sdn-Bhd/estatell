<?php

require_once __DIR__.'/../Support/CommissionSetup.php';

use App\Actions\Commissions\RecordDueCommissions;
use App\Enums\CommissionEntryType;
use App\Enums\CommissionService;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
use App\Models\BusinessDate;
use App\Models\CommissionEntry;
use App\Models\Reservation;

/**
 * @return array<string, string> Entry type (and service) to the sum of its amounts.
 */
function ledgerFor(Reservation $reservation): array
{
    return CommissionEntry::query()
        ->where('reservation_id', $reservation->id)
        ->orderBy('id')
        ->get()
        ->groupBy(fn (CommissionEntry $entry): string => $entry->type->value)
        ->map(fn ($entries): string => number_format((float) $entries->sum('amount'), 2, '.', ''))
        ->all();
}

test('a checked-out stay records the money and 10% of the room total, on the business date', function () {
    $booking = commissionBooking();
    commissionFolioLine($booking['reservation'], SystemTransactionCode::RoomRevenue, '200.00');
    commissionFolioLine($booking['reservation'], SystemTransactionCode::SstTax, '16.00', TransactionKind::Tax);
    endCommissionStay($booking['reservation']);

    expect(app(RecordDueCommissions::class)())->toBe(1);

    $commission = CommissionEntry::query()->where('type', CommissionEntryType::Commission)->sole();
    $businessDate = BusinessDate::query()->where('property_id', $booking['property']->id)->value('date');

    expect(ledgerFor($booking['reservation']))->toBe(['collected' => '200.00', 'commission' => '-20.00'])
        ->and($commission->service)->toBe(CommissionService::BookingEngine)
        ->and($commission->base_amount)->toBe('200.00')
        ->and($commission->rate)->toBe('10.00')
        ->and($commission->due_date->toDateString())->toBe($businessDate->toDateString());
});

test('running again writes nothing new', function () {
    $booking = commissionBooking();
    commissionFolioLine($booking['reservation'], SystemTransactionCode::RoomRevenue, '200.00');
    endCommissionStay($booking['reservation']);

    app(RecordDueCommissions::class)();
    app(RecordDueCommissions::class)();

    expect(CommissionEntry::query()->count())->toBe(2);
});

test('a stay that has not ended records nothing', function () {
    $booking = commissionBooking();
    commissionFolioLine($booking['reservation'], SystemTransactionCode::RoomRevenue, '200.00');

    expect(app(RecordDueCommissions::class)())->toBe(0)
        ->and(CommissionEntry::query()->count())->toBe(0);
});

test('a night added at the desk is capped at the room total booked online', function () {
    $booking = commissionBooking();
    commissionFolioLine($booking['reservation'], SystemTransactionCode::RoomRevenue, '200.00');
    commissionFolioLine($booking['reservation'], SystemTransactionCode::RoomRevenue, '120.00');
    endCommissionStay($booking['reservation']);

    app(RecordDueCommissions::class)();

    expect(ledgerFor($booking['reservation'])['commission'])->toBe('-20.00');
});

test('an early departure counts the nights stayed, the early departure charge and discounts', function () {
    $booking = commissionBooking();
    $night = commissionFolioLine($booking['reservation'], SystemTransactionCode::RoomRevenue, '100.00');
    commissionFolioLine($booking['reservation'], SystemTransactionCode::Discount, '-10.00', TransactionKind::Adjustment, $night);
    commissionFolioLine($booking['reservation'], SystemTransactionCode::EarlyDepartureRevenue, '50.00');
    endCommissionStay($booking['reservation']);

    app(RecordDueCommissions::class)();

    expect(CommissionEntry::query()->where('type', CommissionEntryType::Commission)->sole()->base_amount)->toBe('140.00')
        ->and(ledgerFor($booking['reservation'])['commission'])->toBe('-14.00');
});

test('a cancellation with a fee kept pays commission on the fee before tax, and the refund costs RM 0.50', function () {
    $booking = commissionBooking();
    commissionFolioLine($booking['reservation'], SystemTransactionCode::CancellationRevenue, '100.00');
    commissionFolioLine($booking['reservation'], SystemTransactionCode::SstTax, '8.00', TransactionKind::Tax);
    processedCommissionRefund($booking['payment'], '92.00');
    endCommissionStay($booking['reservation'], UnitStatus::Cancelled);

    app(RecordDueCommissions::class)();

    expect(ledgerFor($booking['reservation']))->toBe([
        'collected' => '200.00',
        'refunded' => '-92.00',
        'refund_fee' => '-0.50',
        'commission' => '-10.00',
    ]);
});

test('a free cancellation pays no commission, and the full refund also costs the fee Curlec kept', function () {
    $booking = commissionBooking();
    processedCommissionRefund($booking['payment'], '200.00');
    endCommissionStay($booking['reservation'], UnitStatus::Cancelled);

    app(RecordDueCommissions::class)();

    expect(ledgerFor($booking['reservation']))->toBe([
        'collected' => '200.00',
        'refunded' => '-200.00',
        'refund_fee' => '-5.22',
    ]);
});

test('without Curlec\'s fee on the payment, a full refund costs RM 0.50 only', function () {
    $booking = commissionBooking(curlecFeeSen: null);
    processedCommissionRefund($booking['payment'], '200.00');
    endCommissionStay($booking['reservation'], UnitStatus::Cancelled);

    app(RecordDueCommissions::class)();

    expect(ledgerFor($booking['reservation'])['refund_fee'])->toBe('-0.50');
});

test('one room of two cancelled free pays commission on the room that stayed', function () {
    $booking = commissionBooking(rooms: 2);
    commissionFolioLine($booking['reservation'], SystemTransactionCode::RoomRevenue, '200.00');
    processedCommissionRefund($booking['payment'], '200.00');
    endCommissionUnit($booking['reservation']->units()->orderBy('id')->first(), UnitStatus::CheckedOut);
    endCommissionUnit($booking['reservation']->units()->orderByDesc('id')->first(), UnitStatus::Cancelled);

    app(RecordDueCommissions::class)();

    expect(ledgerFor($booking['reservation']))->toBe([
        'collected' => '400.00',
        'refunded' => '-200.00',
        'refund_fee' => '-0.50',
        'commission' => '-20.00',
    ]);
});

test('a refund after the booking ended is added on the next run', function () {
    $booking = commissionBooking();
    commissionFolioLine($booking['reservation'], SystemTransactionCode::RoomRevenue, '200.00');
    endCommissionStay($booking['reservation']);
    app(RecordDueCommissions::class)();

    processedCommissionRefund($booking['payment'], '50.00');
    app(RecordDueCommissions::class)();

    expect(ledgerFor($booking['reservation']))->toBe([
        'collected' => '200.00',
        'commission' => '-20.00',
        'refunded' => '-50.00',
        'refund_fee' => '-0.50',
    ]);
});

test('a fee reversed on the folio reverses the commission, and a new amount posts a new entry', function () {
    $booking = commissionBooking();
    $fee = commissionFolioLine($booking['reservation'], SystemTransactionCode::CancellationRevenue, '100.00');
    endCommissionStay($booking['reservation'], UnitStatus::Cancelled);
    app(RecordDueCommissions::class)();

    commissionFolioLine($booking['reservation'], SystemTransactionCode::CancellationRevenue, '-100.00');
    commissionFolioLine($booking['reservation'], SystemTransactionCode::CancellationRevenue, '60.00');
    app(RecordDueCommissions::class)();

    $entries = CommissionEntry::query()->where('type', CommissionEntryType::Commission)->orderBy('id')->get();

    expect($entries->pluck('amount')->all())->toBe(['-10.00', '10.00', '-6.00'])
        ->and($entries[1]->reverses_entry_id)->toBe($entries[0]->id)
        ->and($entries[2]->idempotency_key)->toBe('commission:booking_engine:'.$booking['reservation']->id.':2');
});

test('a reservation not from the booking engine records nothing', function () {
    $booking = commissionBooking();
    $direct = Reservation::factory()->create(['property_id' => $booking['property']->id]);
    endCommissionStay($direct);

    app(RecordDueCommissions::class)();

    expect(CommissionEntry::query()->where('reservation_id', $direct->id)->count())->toBe(0);
});

test('a late payment refunded because the rooms are taken records nothing', function () {
    $setup = curlecSetup();
    $hold = curlecHold($setup, 'expired');
    $payment = curlecOrder($hold);
    $payment->update(['status' => 'paid']);
    processedCommissionRefund($payment, '200.00');

    expect(app(RecordDueCommissions::class)())->toBe(0)
        ->and(CommissionEntry::query()->count())->toBe(0);
});
