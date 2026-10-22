<?php

use App\Actions\Onboarding\Wastays\ReplayWastaysDay;
use App\Enums\FolioStatus;
use App\Enums\PolicyScope;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
use App\Models\BusinessDate;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\ImportLink;
use App\Models\Payment;
use App\Models\Rate;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\RoomRevenuePolicy;
use App\Models\TransactionCode;

require_once __DIR__.'/../Support/WastaysReplaySetup.php';

/** @param array<string, mixed> $setup */
function correctionsSetup(): array
{
    $setup = wastaysReplaySetup();
    $property = $setup['property'];

    foreach ([SystemTransactionCode::Adjustment, SystemTransactionCode::Refund, SystemTransactionCode::EarlyDepartureRevenue, SystemTransactionCode::CancellationRevenue] as $code) {
        TransactionCode::factory()->for($property)->system($code)->create();
    }

    RoomRevenuePolicy::factory()->for($property)->forCode(SystemTransactionCode::CancellationRevenue, PolicyScope::FirstNight)->create();
    RoomRevenuePolicy::factory()->for($property)->forCode(SystemTransactionCode::EarlyDepartureRevenue, PolicyScope::UnusedNights)->create();
    Rate::factory()->create(['rate_plan_id' => $setup['plan']->id, 'room_type_id' => $setup['roomType']->id, 'date' => '2026-08-16', 'amount' => '100.00']);

    return $setup;
}

function replay(WastaysDayTarget $x, string $date)
{
    return app(ReplayWastaysDay::class)($x->target, $x->day, $date);
}

/** @return array<string, string|null> */
function lineRow(string $id, string $booking, string $category, string $type, string $code, string $amount, string $at, array $extra = []): array
{
    return [
        'id' => $id, 'source_booking_id' => $booking, 'category' => $category, 'transaction_type' => $type, 'transaction_code_id' => $code, 'amount' => $amount,
        'created_at' => $at, 'description' => 'Invented line', 'reversal_of_transaction_id' => null, 'moved_from_transaction_id' => null,
        'posting_date' => substr($at, 0, 10), 'metadata' => '{}', ...$extra,
    ];
}

final class WastaysDayTarget
{
    public function __construct(public $target, public $day) {}
}

function correctionsTarget(array $setup, array $tables): WastaysDayTarget
{
    [$target, $day] = wastaysReplayTarget($setup, $tables);

    return new WastaysDayTarget($target, $day);
}

it('runs the wastays night audit last, opens the next date and writes nothing on a second run', function () {
    $setup = correctionsSetup();
    $x = correctionsTarget($setup, wastaysReplayTables(['night_audits' => [
        ['id' => '1', 'business_date' => '2026-08-13'], ['id' => '2', 'business_date' => '2026-08-14'],
    ]]));

    $first = replay($x, '2026-08-13');
    $links = ImportLink::query()->count();
    $lines = FolioLine::query()->count();
    $again = replay($x, '2026-08-13');

    expect($first->counts)->toMatchArray(['night audits run' => 1])
        ->and(BusinessDate::query()->where('property_id', $setup['property']->id)->current()->first()->date->toDateString())->toBe('2026-08-14')
        ->and(FolioLine::query()->where('kind', TransactionKind::Charge)->count())->toBe(2)
        ->and($again->counts)->toBe(['day already replayed' => 1])
        ->and(ImportLink::query()->count())->toBe($links)
        ->and(FolioLine::query()->count())->toBe($lines);

    replay($x, '2026-08-14');
    $third = replay($x, '2026-08-15');

    expect($third->counts)->toMatchArray(['check-outs' => 2])
        ->and($third->notes)->toHaveCount(1)
        ->and(Folio::query()->where('status', FolioStatus::Closed)->count())->toBe(2);
});

it('charges a repaired night at its first price and changes it on the day of the repair', function () {
    $setup = correctionsSetup();
    $tables = wastaysReplayTables(['night_audits' => [['id' => '1', 'business_date' => '2026-08-13'], ['id' => '2', 'business_date' => '2026-08-14']]]);
    $tables['booking_rooms'][0]['nightly_rate_snapshot'] = json_encode(['2026-08-13' => ['price' => '130.0'], '2026-08-14' => ['price' => '120.0']]);
    $source = fn (string $source) => json_encode(['posting_source' => $source]);
    $tables['folio_transactions'][] = lineRow('20', '1', 'accommodation', 'charge', '30', '120.00', '2026-08-13T16:00:00.000000Z', ['posting_date' => '2026-08-13', 'metadata' => $source('night_audit')]);
    $tables['folio_transactions'][] = lineRow('21', '1', 'correction', 'adjustment', '30', '-120.00', '2026-08-14T05:00:00.000000Z', ['reversal_of_transaction_id' => '20', 'posting_date' => '2026-08-13', 'metadata' => $source('historical_nightly_charge_repair')]);
    $tables['folio_transactions'][] = lineRow('22', '1', 'accommodation', 'charge', '30', '130.00', '2026-08-14T05:00:01.000000Z', ['moved_from_transaction_id' => '20', 'posting_date' => '2026-08-13', 'metadata' => $source('historical_nightly_charge_repair')]);
    $x = correctionsTarget($setup, $tables);

    replay($x, '2026-08-13');
    $unit = ReservationUnit::query()->whereHas('reservation', fn ($query) => $query->where('reference', 'like', '%100041'))->sole();
    $firstNight = fn () => $unit->nights()->whereDate('date', '2026-08-13')->first();

    expect($firstNight()->override_amount)->toBe('120.00');

    $second = replay($x, '2026-08-14');
    $roomLines = FolioLine::query()->whereHas('folio', fn ($query) => $query->where('reservation_unit_id', $unit->id))->whereDate('service_date', '2026-08-13')->whereHas('transactionCode', fn ($code) => $code->where('system_key', 'room_revenue'))->orderBy('id')->pluck('amount')->all();

    expect($second->counts)->toMatchArray(['price repairs' => 1, 'reversals done by the price repair' => 1])
        ->and($firstNight()->override_amount)->toBe('130.00')
        ->and($roomLines)->toBe(['120.00', '-120.00', '130.00']);
});

it('voids a payment taken the same day, posts an adjustment and gives a refund', function () {
    $setup = correctionsSetup();
    $tables = wastaysReplayTables();
    $tables['folio_transactions'][] = lineRow('40', '1', 'refund', 'payment', '31', '-100.00', '2026-08-13T04:30:00.000000Z', ['reversal_of_transaction_id' => '10']);
    $tables['folio_transactions'][] = lineRow('41', '1', 'adjustment', 'adjustment', '31', '-12.96', '2026-08-13T05:30:00.000000Z');
    $tables['folio_transactions'][] = lineRow('42', '1', 'cash', 'payment', '31', '60.00', '2026-08-13T05:40:00.000000Z');
    $tables['folio_transactions'][] = lineRow('43', '1', 'refund', 'payment', '31', '-0.01', '2026-08-13T05:50:00.000000Z');
    $tables['receipts'][] = ['id' => '9', 'folio_transaction_id' => '42', 'deposit_id' => null, 'receipt_number' => '80'];
    $x = correctionsTarget($setup, $tables);

    $summary = replay($x, '2026-08-13');
    $payments = Payment::query()->orderBy('id')->get();

    expect($summary->counts)->toMatchArray(['payments voided or refunded' => 1, 'adjustments posted' => 1, 'refunds given' => 1])
        ->and($payments->whereNotNull('refund_of_id')->first()->voided)->toBeTrue()
        ->and($payments->whereNotNull('refund_of_id')->pluck('amount')->map(fn ($amount) => (string) $amount)->all())->toContain('100.00', '0.01')
        ->and(FolioLine::query()->where('amount', '-12.96')->where('kind', TransactionKind::Adjustment)->exists())->toBeTrue();
});

it('cancels a booking and extends a stay with the wastays price of the added night', function () {
    $setup = correctionsSetup();
    $tables = wastaysReplayTables();
    $tables['bookings'][0]['check_out'] = '2026-08-16 00:00:00';
    $tables['booking_rooms'][0]['nightly_rate_snapshot'] = json_encode(['2026-08-13' => ['price' => '120.0'], '2026-08-14' => ['price' => '120.0'], '2026-08-15' => ['price' => '140.0']]);
    $tables['booking_audit_logs'][] = ['id' => '9', 'auditable_type' => 'Booking', 'auditable_id' => '1', 'action_type' => 'update', 'occurred_at' => '2026-08-14T02:00:00.000000Z',
        'old_value' => json_encode(['check_in' => '2026-08-13', 'check_out' => '2026-08-15']), 'new_value' => json_encode(['check_in' => '2026-08-13', 'check_out' => '2026-08-16'])];
    $tables['bookings'][] = ['id' => '3', 'source' => 'walk_in', 'created_at' => '2026-08-13T02:30:00.000000Z', 'check_in' => '2026-08-14 00:00:00', 'check_out' => '2026-08-15 00:00:00', 'adults' => '1', 'children' => '0', 'reservation_number' => '43'];
    $tables['bookings'][1]['reservation_number'] = '44';
    $tables['booking_rooms'][] = ['id' => '3', 'booking_id' => '3', 'room_type_id' => '1', 'rate_plan_id' => '5', 'room_id' => '22', 'nightly_rate_snapshot' => json_encode(['2026-08-14' => ['price' => '100.0']])];
    $tables['booking_guests'][] = ['id' => '3', 'booking_id' => '3', 'guest_id' => '11', 'role' => 'primary'];
    $tables['booking_audit_logs'][] = ['id' => '10', 'auditable_type' => 'Booking', 'auditable_id' => '3', 'action_type' => 'cancel', 'occurred_at' => '2026-08-13T03:30:00.000000Z', 'old_value' => '{}', 'new_value' => '{}'];
    $tables['night_audits'] = [['id' => '1', 'business_date' => '2026-08-13']];
    $x = correctionsTarget($setup, $tables);

    $first = replay($x, '2026-08-13');
    $cancelled = ReservationUnit::query()->whereHas('reservation', fn ($query) => $query->where('reference', 'like', '%100043'))->sole();

    expect($first->counts)->toMatchArray(['bookings cancelled or voided' => 1])
        ->and($cancelled->status)->toBe(UnitStatus::Cancelled);

    $unit = ReservationUnit::query()->whereHas('reservation', fn ($query) => $query->where('reference', 'like', '%100041'))->sole();
    expect($unit->nights()->count())->toBe(2);

    $x->day->timeline('2026-08-14');
    $second = replay($x, '2026-08-14');

    expect($second->counts)->toMatchArray(['stay dates changed' => 1])
        ->and($unit->refresh()->departure_date->toDateString())->toBe('2026-08-16')
        ->and($unit->nights()->orderBy('date')->pluck('override_amount')->all())->toBe(['120.00', '120.00', '140.00']);
});

it('checks a guest out early with the wastays fee and invoice number', function () {
    $setup = correctionsSetup();
    $prefix = $setup['property']->document_prefix;
    $tables = wastaysReplayTables();
    $tables['bookings'] = [array_merge($tables['bookings'][0], ['check_out' => '2026-08-14 00:00:00'])];
    $tables['booking_rooms'] = [array_merge($tables['booking_rooms'][0], ['nightly_rate_snapshot' => json_encode(['2026-08-13' => ['price' => '120.0'], '2026-08-14' => ['price' => '120.0'], '2026-08-15' => ['price' => '120.0']])])];
    $tables['booking_guests'] = [$tables['booking_guests'][0]];
    $tables['booking_audit_logs'] = [$tables['booking_audit_logs'][0], ['id' => '5', 'auditable_type' => 'Booking', 'auditable_id' => '1', 'action_type' => 'check_out', 'occurred_at' => '2026-08-14T05:01:00.000000Z', 'old_value' => '{}', 'new_value' => '{}']];
    $tables['folio_transactions'] = [
        $tables['folio_transactions'][0],
        lineRow('12', '1', 'cash', 'payment', '31', '140.00', '2026-08-14T04:00:00.000000Z'),
        lineRow('30', '1', 'early_departure_charge', 'charge', '31', '120.00', '2026-08-14T05:00:00.000000Z'),
    ];
    $tables['folio_transactions'][1]['metadata'] = '{}';
    $tables['receipts'] = [$tables['receipts'][0], ['id' => '3', 'folio_transaction_id' => '12', 'deposit_id' => null, 'receipt_number' => '78']];
    $tables['invoices'] = [$tables['invoices'][0]];
    $tables['booking_folios'] = [$tables['booking_folios'][0]];
    $tables['night_audits'] = [['id' => '1', 'business_date' => '2026-08-13'], ['id' => '2', 'business_date' => '2026-08-14']];
    unset($tables['folio_transactions'][0]['metadata']);
    $x = correctionsTarget($setup, $tables);

    replay($x, '2026-08-13');
    $second = replay($x, '2026-08-14');
    $unit = ReservationUnit::query()->sole();

    expect($second->counts)->toMatchArray(['early check-outs' => 1, 'night audits run' => 1])
        ->and($unit->status)->toBe(UnitStatus::CheckedOut)
        ->and($unit->departure_date->toDateString())->toBe('2026-08-14')
        ->and($unit->nights()->count())->toBe(1)
        ->and(FolioLine::query()->where('description', 'Early departure fee')->sole()->amount)->toBe('120.00')
        ->and(Folio::query()->sole()->invoice_number)->toBe("{$prefix}-26700009")
        ->and(Reservation::query()->count())->toBe(1);
});

it('checks a same-day leaver out on the arrival day with the one night kept and the wastays invoice number', function () {
    $setup = correctionsSetup();
    $prefix = $setup['property']->document_prefix;
    $tables = wastaysReplayTables();
    $tables['bookings'] = [array_merge($tables['bookings'][0], ['check_out' => '2026-08-13 00:00:00'])];
    $tables['booking_rooms'] = [array_merge($tables['booking_rooms'][0], ['nightly_rate_snapshot' => json_encode(['2026-08-13' => ['price' => '120.0']])])];
    $tables['booking_guests'] = [$tables['booking_guests'][0]];
    $tables['booking_audit_logs'] = [$tables['booking_audit_logs'][0], ['id' => '5', 'auditable_type' => 'Booking', 'auditable_id' => '1', 'action_type' => 'check_out', 'occurred_at' => '2026-08-13T05:01:00.000000Z', 'old_value' => '{}', 'new_value' => '{}']];
    $tables['folio_transactions'] = [
        array_merge($tables['folio_transactions'][0], ['amount' => '120.00']),
        lineRow('30', '1', 'early_departure_charge', 'charge', '31', '120.00', '2026-08-13T05:00:00.000000Z'),
    ];
    $tables['receipts'] = [$tables['receipts'][0]];
    $tables['invoices'] = [$tables['invoices'][0]];
    $tables['booking_folios'] = [$tables['booking_folios'][0]];
    $tables['night_audits'] = [['id' => '1', 'business_date' => '2026-08-13']];
    unset($tables['folio_transactions'][0]['metadata']);
    $x = correctionsTarget($setup, $tables);

    $first = replay($x, '2026-08-13');
    $unit = ReservationUnit::query()->sole();

    expect($first->counts)->toMatchArray(['same-day leavers checked out on the arrival day' => 1, 'night audits run' => 1])
        ->and($unit->status)->toBe(UnitStatus::CheckedOut)
        ->and($unit->departure_date->toDateString())->toBe('2026-08-14')
        ->and(FolioLine::query()->where('kind', TransactionKind::Charge)->pluck('amount')->all())->toBe(['120.00'])
        ->and(FolioLine::query()->where('description', 'Early departure fee')->exists())->toBeFalse()
        ->and(Folio::query()->sole()->status)->toBe(FolioStatus::Closed)
        ->and(Folio::query()->sole()->invoice_number)->toBe("{$prefix}-26700009");
});

it('creates a stay moved into a closed night on its creation day as a backdated stay', function () {
    $setup = correctionsSetup();
    $tables = wastaysReplayTables();
    $audit = fn (string $id, string $action, string $at, array $old = [], array $new = []): array => [
        'id' => $id, 'auditable_type' => 'Booking', 'auditable_id' => '1', 'action_type' => $action, 'occurred_at' => $at, 'old_value' => json_encode($old), 'new_value' => json_encode($new),
    ];
    $tables['bookings'] = [array_merge($tables['bookings'][0], ['created_at' => '2026-08-14T02:00:00.000000Z', 'check_in' => '2026-08-13 00:00:00', 'check_out' => '2026-08-13 00:00:00'])];
    $tables['booking_rooms'] = [array_merge($tables['booking_rooms'][0], ['nightly_rate_snapshot' => json_encode(['2026-08-13' => ['price' => '120.0']])])];
    $tables['booking_guests'] = [$tables['booking_guests'][0]];
    $tables['booking_audit_logs'] = [
        $audit('1', 'check_in', '2026-08-14T02:01:00.000000Z'),
        $audit('2', 'update', '2026-08-14T02:05:00.000000Z', ['check_in' => '2026-08-14T14:00:00.000+08:00', 'check_out' => '2026-08-15T12:00:00.000+08:00'], ['check_in' => '2026-08-13T14:00:00.000+08:00', 'check_out' => '2026-08-14T12:00:00.000+08:00']),
        $audit('3', 'check_out', '2026-08-14T05:01:00.000000Z'),
    ];
    $tables['folio_transactions'] = [
        lineRow('10', '1', 'cash', 'payment', '31', '120.00', '2026-08-14T03:00:00.000000Z'),
        lineRow('30', '1', 'early_departure_charge', 'charge', '31', '120.00', '2026-08-14T05:00:00.000000Z'),
    ];
    $tables['receipts'] = [$tables['receipts'][0]];
    $tables['invoices'] = [$tables['invoices'][0]];
    $tables['booking_folios'] = [$tables['booking_folios'][0]];
    $tables['night_audits'] = [['id' => '1', 'business_date' => '2026-08-13'], ['id' => '2', 'business_date' => '2026-08-14']];
    $x = correctionsTarget($setup, $tables);

    replay($x, '2026-08-13');
    $second = replay($x, '2026-08-14');

    $unit = ReservationUnit::query()->sole();

    expect($second->counts)->toMatchArray(['backdated booking checked in as of its arrival' => 1, 'stay dates already set when created' => 1])
        ->and($unit->arrival_date->toDateString())->toBe('2026-08-13')
        ->and($unit->status)->toBe(UnitStatus::CheckedOut)
        ->and(FolioLine::query()->where('kind', TransactionKind::Charge)->pluck('amount')->all())->toBe(['120.00'])
        ->and(Folio::query()->sole()->status)->toBe(FolioStatus::Closed);
});

it('checks a guest in when wastays checked them in before it moved the stay to an earlier arrival', function () {
    $setup = correctionsSetup();
    $tables = wastaysReplayTables();
    $audit = fn (string $id, string $action, string $at, array $old = [], array $new = []): array => [
        'id' => $id, 'auditable_type' => 'Booking', 'auditable_id' => '1', 'action_type' => $action, 'occurred_at' => $at, 'old_value' => json_encode($old), 'new_value' => json_encode($new),
    ];
    $tables['bookings'] = [array_merge($tables['bookings'][0], ['check_in' => '2026-08-13 00:00:00', 'check_out' => '2026-08-14 00:00:00'])];
    $tables['booking_rooms'] = [array_merge($tables['booking_rooms'][0], ['nightly_rate_snapshot' => json_encode(['2026-08-13' => ['price' => '120.0']])])];
    $tables['booking_guests'] = [$tables['booking_guests'][0]];
    $tables['booking_audit_logs'] = [
        $audit('1', 'check_in', '2026-08-13T02:01:00.000000Z'),
        $audit('2', 'update', '2026-08-13T02:05:00.000000Z', ['check_in' => '2026-08-14T14:00:00.000+08:00', 'check_out' => '2026-08-15T12:00:00.000+08:00'], ['check_in' => '2026-08-13T14:00:00.000+08:00', 'check_out' => '2026-08-14T12:00:00.000+08:00']),
    ];
    $tables['folio_transactions'] = [lineRow('10', '1', 'cash', 'payment', '31', '120.00', '2026-08-13T03:00:00.000000Z')];
    $tables['receipts'] = [$tables['receipts'][0]];
    $tables['invoices'] = [];
    $tables['booking_folios'] = [$tables['booking_folios'][0]];
    $tables['night_audits'] = [['id' => '1', 'business_date' => '2026-08-13']];
    $x = correctionsTarget($setup, $tables);

    $first = replay($x, '2026-08-13');
    $unit = ReservationUnit::query()->sole();

    expect($first->counts)->toMatchArray(['check-ins before the arrival date moved' => 1, 'stay dates changed' => 1, 'check-ins after the stay moved earlier' => 1])
        ->and($unit->status)->toBe(UnitStatus::CheckedIn)
        ->and($unit->arrival_date->toDateString())->toBe('2026-08-13')
        ->and(FolioLine::query()->where('kind', TransactionKind::Charge)->pluck('amount')->all())->toBe(['120.00']);
});

it('undoes a check-out before reversing its early departure fee and gives the removed nights back', function () {
    $setup = correctionsSetup();
    $prefix = $setup['property']->document_prefix;
    $tables = wastaysReplayTables();
    $log = fn (string $id, string $action, string $at, array $new = []): array => [
        'id' => $id, 'auditable_type' => 'Booking', 'auditable_id' => '1', 'action_type' => $action, 'occurred_at' => $at, 'old_value' => '{}', 'new_value' => json_encode($new),
    ];
    $tables['bookings'] = [array_merge($tables['bookings'][0], ['check_out' => '2026-08-16 00:00:00'])];
    $tables['booking_rooms'] = [array_merge($tables['booking_rooms'][0], ['nightly_rate_snapshot' => json_encode(['2026-08-13' => ['price' => '120.0'], '2026-08-14' => ['price' => '120.0'], '2026-08-15' => ['price' => '120.0']])])];
    $tables['booking_guests'] = [$tables['booking_guests'][0]];
    $tables['booking_audit_logs'] = [
        $tables['booking_audit_logs'][0],
        $log('5', 'check_out', '2026-08-14T05:01:00.000000Z'),
        $log('6', 'undo_check_out', '2026-08-14T08:00:01.000000Z', ['status' => 'checked_in', 'check_out' => '2026-08-16']),
    ];
    $tables['folio_transactions'] = [
        array_merge($tables['folio_transactions'][0], ['amount' => '360.00']),
        lineRow('30', '1', 'early_departure_charge', 'charge', '31', '240.00', '2026-08-14T05:00:00.000000Z'),
        lineRow('31', '1', 'correction', 'adjustment', '31', '-240.00', '2026-08-14T08:00:00.000000Z', ['reversal_of_transaction_id' => '30', 'correction_reason' => 'undo_checkout', 'metadata' => json_encode(['reversal_group_parent_id' => 30])]),
        lineRow('32', '1', 'correction', 'adjustment', '31', '-19.20', '2026-08-14T08:00:00.000000Z', ['reversal_of_transaction_id' => '99', 'correction_reason' => 'undo_checkout', 'metadata' => json_encode(['reversal_group_parent_id' => 30])]),
    ];
    $tables['receipts'] = [$tables['receipts'][0]];
    $tables['invoices'] = [$tables['invoices'][0]];
    $tables['booking_folios'] = [$tables['booking_folios'][0]];
    $tables['night_audits'] = [['id' => '1', 'business_date' => '2026-08-13'], ['id' => '2', 'business_date' => '2026-08-14']];
    unset($tables['folio_transactions'][0]['metadata']);
    $x = correctionsTarget($setup, $tables);

    replay($x, '2026-08-13');
    $second = replay($x, '2026-08-14');
    $unit = ReservationUnit::query()->sole();
    $folio = Folio::query()->sole();

    expect($second->counts)->toMatchArray(['early check-outs' => 1, 'check-outs undone' => 1, 'lines reversed' => 1, 'tax lines reversed with their charge' => 1])
        ->and($unit->status)->toBe(UnitStatus::CheckedIn)
        ->and($unit->departure_date->toDateString())->toBe('2026-08-16')
        ->and(FolioLine::query()->where('description', 'Early departure fee')->pluck('amount')->all())->toBe(['240.00', '-240.00'])
        ->and($folio->status)->toBe(FolioStatus::Open)
        ->and($folio->invoice_number)->toBe("{$prefix}-26700009");
});

it('posts one early departure fee for each wastays line and checks the guest out after the last', function () {
    $setup = correctionsSetup();
    $tables = wastaysReplayTables();
    $tables['bookings'] = [array_merge($tables['bookings'][0], ['check_out' => '2026-08-14 00:00:00'])];
    $tables['booking_rooms'] = [array_merge($tables['booking_rooms'][0], ['nightly_rate_snapshot' => json_encode(['2026-08-13' => ['price' => '120.0'], '2026-08-14' => ['price' => '120.0'], '2026-08-15' => ['price' => '120.0']])])];
    $tables['booking_guests'] = [$tables['booking_guests'][0]];
    $tables['booking_audit_logs'] = [$tables['booking_audit_logs'][0], ['id' => '5', 'auditable_type' => 'Booking', 'auditable_id' => '1', 'action_type' => 'check_out', 'occurred_at' => '2026-08-14T05:01:00.000000Z', 'old_value' => '{}', 'new_value' => '{}']];
    $tables['folio_transactions'] = [
        $tables['folio_transactions'][0],
        lineRow('12', '1', 'cash', 'payment', '31', '140.00', '2026-08-14T04:00:00.000000Z'),
        lineRow('30', '1', 'early_departure_charge', 'charge', '31', '60.00', '2026-08-14T05:00:00.000000Z'),
        lineRow('31', '1', 'early_departure_charge', 'charge', '31', '60.00', '2026-08-14T05:00:00.000000Z'),
    ];
    $tables['receipts'] = [$tables['receipts'][0], ['id' => '3', 'folio_transaction_id' => '12', 'deposit_id' => null, 'receipt_number' => '78']];
    $tables['invoices'] = [$tables['invoices'][0]];
    $tables['booking_folios'] = [$tables['booking_folios'][0]];
    $tables['night_audits'] = [['id' => '1', 'business_date' => '2026-08-13'], ['id' => '2', 'business_date' => '2026-08-14']];
    unset($tables['folio_transactions'][0]['metadata']);
    $x = correctionsTarget($setup, $tables);

    replay($x, '2026-08-13');
    $second = replay($x, '2026-08-14');

    expect($second->counts)->toMatchArray(['early check-outs' => 1])
        ->and(ReservationUnit::query()->sole()->status)->toBe(UnitStatus::CheckedOut)
        ->and(FolioLine::query()->where('description', 'Early departure fee')->pluck('amount')->all())->toBe(['60.00', '60.00'])
        ->and(ImportLink::query()->where('source_table', 'folio_transactions')->whereIn('source_id', ['30', '31'])->count())->toBe(2);
});
