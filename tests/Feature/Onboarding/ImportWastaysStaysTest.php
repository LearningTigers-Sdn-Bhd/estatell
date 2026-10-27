<?php

use App\Actions\Onboarding\Wastays\ImportWastaysStays;
use App\Enums\PaymentMethodType;
use App\Enums\SystemTransactionCode;
use App\Models\FolioLine;
use App\Models\ImportLink;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\TransactionCode;
use App\Services\Onboarding\Wastays\WastaysBookingIndex;
use App\Services\Onboarding\Wastays\WastaysBundle;
use App\Services\Onboarding\Wastays\WastaysImportTarget;
use Carbon\CarbonImmutable;

require_once __DIR__.'/../Support/WastaysReplaySetup.php';

/**
 * Five bookings against the go-live date 13 Aug: a completed stay that ended before it, a confirmed booking with a
 * payment and a refund, an OTA booking, a cancelled booking and a stay still due out, which comes across as a Migrated stay.
 *
 * @return array<string, list<array<string, string|null>>>
 */
function wastaysStayTables(): array
{
    $booking = fn (string $id, string $status, string $in, string $out, string $source, string $number): array => [
        'id' => $id, 'status' => $status, 'source' => $source, 'check_in' => "{$in} 00:00:00", 'check_out' => "{$out} 00:00:00", 'adults' => '2', 'children' => '0',
        'reservation_number' => $number, 'created_at' => '2026-08-01T02:00:00.000000Z',
    ];

    return [
        'bookings' => [
            $booking('1', 'completed', '2026-08-10', '2026-08-12', 'phone', '41'),
            $booking('2', 'confirmed', '2026-08-13', '2026-08-15', 'phone', '42'),
            $booking('3', 'confirmed', '2026-08-13', '2026-08-15', 'ota', '43'),
            $booking('4', 'cancelled', '2026-08-25', '2026-08-26', 'phone', '44'),
            $booking('5', 'due_out_detected', '2026-08-11', '2026-08-12', 'phone', '45'),
        ],
        'booking_rooms' => array_map(fn (string $id): array => [
            'id' => $id, 'booking_id' => $id, 'room_type_id' => '1', 'rate_plan_id' => '5', 'room_id' => match ($id) {
                '1' => '21', '5' => '22', default => ''
            }, 'room_number' => '101',
            'nightly_rate_snapshot' => json_encode(['2026-08-13' => ['price' => '100.0'], '2026-08-14' => ['price' => '100.0']]),
        ], ['1', '2', '3', '4', '5']),
        'booking_guests' => array_map(fn (string $id): array => ['id' => $id, 'booking_id' => $id, 'guest_id' => '11', 'role' => 'primary'], ['1', '2', '3', '4', '5']),
        'booking_folios' => [['id' => '1', 'booking_id' => '2', 'payer_type' => 'guest'], ['id' => '2', 'booking_id' => '5', 'payer_type' => 'guest']],
        'folio_transactions' => [
            ['id' => '10', 'booking_folio_id' => '1', 'transaction_type' => 'payment', 'category' => 'booking_payment', 'amount' => '500.00', 'created_at' => '2026-08-05T02:00:00.000000Z', 'metadata' => '{}', 'reversal_of_transaction_id' => null, 'moved_from_transaction_id' => null],
            ['id' => '12', 'booking_folio_id' => '2', 'transaction_type' => 'charge', 'category' => 'accommodation', 'amount' => '300.00', 'source_booking_id' => '5', 'posting_date' => '2026-08-11', 'created_at' => '2026-08-11T02:00:00.000000Z', 'metadata' => '{}', 'reversal_of_transaction_id' => null, 'moved_from_transaction_id' => null],
            ['id' => '13', 'booking_folio_id' => '2', 'transaction_type' => 'payment', 'category' => 'cash', 'amount' => '50.00', 'source_booking_id' => '5', 'posting_date' => '2026-08-11', 'created_at' => '2026-08-11T03:00:00.000000Z', 'metadata' => '{}', 'reversal_of_transaction_id' => null, 'moved_from_transaction_id' => null],
            ['id' => '11', 'booking_folio_id' => '1', 'transaction_type' => 'payment', 'category' => 'refund', 'amount' => '-100.00', 'created_at' => '2026-08-08T02:00:00.000000Z', 'metadata' => '{}', 'reversal_of_transaction_id' => null, 'moved_from_transaction_id' => null],
        ],
        'receipts' => [['id' => '1', 'folio_transaction_id' => '10', 'deposit_id' => null, 'receipt_number' => '9', 'public_number' => 'X-26500009']],
    ];
}

beforeEach(function () {
    $this->setup = wastaysReplaySetup();
    PaymentMethod::factory()->create(['property_id' => $this->setup['property']->id, 'type' => PaymentMethodType::OldSystem, 'requires_reference' => true]);
    TransactionCode::factory()->for($this->setup['property'])->system(SystemTransactionCode::OpeningBalance)->create();
});

function runStayImport(array $tables, bool $dryRun = false)
{
    $path = wastaysDayBundleDir($tables, ['hotel_code' => test()->setup['property']->document_prefix]);
    $target = WastaysImportTarget::resolveForReplay($path, test()->setup['property']->code, test()->setup['admin']->email);

    return app(ImportWastaysStays::class)($target, $dryRun);
}

it('imports an Old stay, a future booking with one old system payment, and leaves the rest', function () {
    $summary = runStayImport(wastaysStayTables());

    expect($summary->counts)->toMatchArray([
        'old stays' => 1,
        'future bookings' => 1,
        'payments from the old system' => 1,
        'OTA bookings left to Channex' => 1,
        'cancelled, voided or no-show bookings left in wastays' => 1,
        'migrated stays in the house' => 1,
    ]);

    $reservations = Reservation::query()->where('property_id', $this->setup['property']->id)->get();
    $payment = Payment::query()->sole();
    $opening = FolioLine::query()->whereHas('transactionCode', fn ($codes) => $codes->where('system_key', SystemTransactionCode::OpeningBalance->value))->sole();

    expect($reservations)->toHaveCount(3)
        ->and($reservations->flatMap->units->pluck('status')->map->value->sort()->values()->all())->toBe(['checked_in', 'checked_out', 'confirmed'])
        ->and($opening->amount)->toBe('250.00')
        ->and($payment->amount)->toBe('400.00')
        ->and($payment->reference)->toBe('wastays X-26500009')
        ->and($payment->paymentMethod->type)->toBe(PaymentMethodType::OldSystem);
});

it('imports once, and writes nothing on a dry run', function () {
    runStayImport(wastaysStayTables(), true);

    expect(Reservation::query()->count())->toBe(0)->and(ImportLink::query()->where('source_table', 'bookings')->count())->toBe(0);

    runStayImport(wastaysStayTables());
    $again = runStayImport(wastaysStayTables());

    expect($again->counts)->toMatchArray(['already imported' => 3])
        ->and(Reservation::query()->count())->toBe(3)
        ->and(Payment::query()->count())->toBe(1);
});

it('lists a stay that cannot be imported and carries on with the others', function () {
    $tables = wastaysStayTables();
    $tables['booking_rooms'][0]['room_id'] = '';
    $tables['booking_rooms'][0]['room_number'] = '999';

    $summary = runStayImport($tables);

    expect($summary->counts)->toMatchArray(['bookings that could not be imported' => 1, 'future bookings' => 1])
        ->and($summary->notes[0])->toContain('bookings 1')
        ->and(Reservation::query()->count())->toBe(2);
});

it('reads the booking dates in the hotel time zone, not in UTC', function () {
    $tables = wastaysStayTables();
    // 03:00 Kuala Lumpur on the 13th is stored on the evening of the 12th, and midnight on the 15th on the 14th.
    $tables['bookings'][1]['check_in'] = '2026-08-12T19:00:00.000000Z';
    $tables['bookings'][1]['check_out'] = '2026-08-14T16:00:00.000000Z';

    $summary = runStayImport($tables);

    $reservation = ReservationUnit::query()->findOrFail(ImportLink::query()->where('source_table', 'bookings')->where('source_id', '2')->value('target_id'));

    expect($summary->counts)->toMatchArray(['future bookings' => 1])
        ->and($reservation->arrival_date->toDateString())->toBe('2026-08-13')
        ->and($reservation->departure_date->toDateString())->toBe('2026-08-15');
});

it('finds what a booking of a group paid beyond or still owes', function () {
    $tables = wastaysStayTables();
    $tables['bookings'][1]['group_booking_id'] = '9';
    $tables['bookings'][0]['group_booking_id'] = '9';
    $tables['folio_transactions'][] = ['id' => '90', 'booking_folio_id' => '1', 'transaction_type' => 'payment', 'category' => 'booking_payment', 'amount' => '700.00', 'source_booking_id' => '2', 'created_at' => '2026-08-06T02:00:00.000000Z', 'metadata' => '{}', 'reversal_of_transaction_id' => null, 'moved_from_transaction_id' => null];
    $tables['folio_transactions'][] = ['id' => '91', 'booking_folio_id' => '1', 'transaction_type' => 'charge', 'category' => 'accommodation', 'amount' => '200.00', 'source_booking_id' => '2', 'created_at' => '2026-08-06T03:00:00.000000Z', 'metadata' => '{}', 'reversal_of_transaction_id' => null, 'moved_from_transaction_id' => null];
    $index = new WastaysBookingIndex(WastaysBundle::open(wastaysDayBundleDir($tables, ['hotel_code' => test()->setup['property']->document_prefix])));

    expect($index->groupBalance('2'))->toBe('500.00')
        ->and($index->groupBalance('1'))->toBeNull()
        ->and($index->groupId('2'))->toBe('9');
});

it('knows a booking that wastays created already cancelled', function () {
    $tables = wastaysStayTables();
    $tables['booking_audit_logs'] = [
        ['id' => '1', 'auditable_type' => 'Booking', 'auditable_id' => '4', 'action_type' => 'create', 'occurred_at' => '2026-08-01T02:00:00.000000Z', 'old_value' => '{}', 'new_value' => '{"status":"cancelled"}'],
        ['id' => '2', 'auditable_type' => 'Booking', 'auditable_id' => '2', 'action_type' => 'create', 'occurred_at' => '2026-08-01T02:00:00.000000Z', 'old_value' => '{}', 'new_value' => '{"status":"confirmed"}'],
    ];
    $index = new WastaysBookingIndex(WastaysBundle::open(wastaysDayBundleDir($tables, ['hotel_code' => test()->setup['property']->document_prefix])));

    expect($index->createdCancelled('4'))->toBeTrue()->and($index->createdCancelled('2'))->toBeFalse()->and($index->createdCancelled('9'))->toBeFalse();
});

it('sees a stay moved to an earlier arrival after a given moment', function () {
    $tables = wastaysStayTables();
    $update = fn (string $id, string $at, string $oldIn, string $newIn): array => ['id' => $id, 'auditable_type' => 'Booking', 'auditable_id' => '2', 'action_type' => 'update', 'occurred_at' => $at, 'old_value' => json_encode(['check_in' => $oldIn]), 'new_value' => json_encode(['check_in' => $newIn])];
    $tables['booking_audit_logs'] = [
        $update('1', '2026-08-13T04:00:00.000000Z', '2026-08-13T03:00:00.000+08:00', '2026-08-12T15:00:00.000+08:00'),
        $update('2', '2026-08-14T04:00:00.000000Z', '2026-08-12T15:00:00.000+08:00', '2026-08-13T15:00:00.000+08:00'),
    ];
    $index = new WastaysBookingIndex(WastaysBundle::open(wastaysDayBundleDir($tables, ['hotel_code' => test()->setup['property']->document_prefix])));

    expect($index->arrivalMovesEarlierAfter('2', CarbonImmutable::parse('2026-08-13T03:00:00Z'), '2026-08-13'))->toBeTrue()
        ->and($index->arrivalMovesEarlierAfter('2', CarbonImmutable::parse('2026-08-13T03:00:00Z'), '2026-08-12'))->toBeFalse()
        ->and($index->arrivalMovesEarlierAfter('2', CarbonImmutable::parse('2026-08-13T05:00:00Z'), '2026-08-13'))->toBeFalse();
});
