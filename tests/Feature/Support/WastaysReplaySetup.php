<?php

use App\Actions\Onboarding\Wastays\RecordImportLink;
use App\Enums\BusinessDateStatus;
use App\Enums\PaymentMethodType;
use App\Enums\SystemTransactionCode;
use App\Models\BusinessDate;
use App\Models\ChargeItem;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\Rate;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\Onboarding\Wastays\WastaysBundle;
use App\Services\Onboarding\Wastays\WastaysDay;
use App\Services\Onboarding\Wastays\WastaysImportTarget;

require_once __DIR__.'/BookingSetup.php';

/**
 * Writes a bundle folder from invented rows. Every row of a table gets the union of the columns, empty where missing.
 *
 * @param  array<string, list<array<string, string|null>>>  $tables
 * @param  array<string, mixed>  $manifest
 */
function wastaysDayBundleDir(array $tables, array $manifest = []): string
{
    foreach ($tables as $name => $rows) {
        $columns = array_values(array_unique(array_merge(...array_map('array_keys', $rows))));
        $tables[$name] = array_map(fn (array $row): array => array_merge(array_fill_keys($columns, null), $row), $rows);
    }

    return wastaysBundleDir($tables, $manifest);
}

/**
 * A live property that trades on 2026-08-13 with two rooms of one type, a 100.00 rate from 13 to 16 Aug, the sources
 * Walk-in and Booking.com, Cash and OTA collected methods, a Cleaning charge item, and the import links the setup and
 * guest imports would have written (room type 1, rooms 21 and 22, rate plan 5, guests 11 and 12, codes 31 to 33).
 *
 * @return array<string, mixed>
 */
function wastaysReplaySetup(): array
{
    $setup = bookingSetup();
    $property = $setup['property'];
    $property->forceFill(['status' => 'live'])->save();
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-08-13', 'status' => BusinessDateStatus::Open]);
    TransactionCode::factory()->for($property)->system(SystemTransactionCode::RoomRevenue)->create();

    foreach (['2026-08-13', '2026-08-14', '2026-08-15'] as $date) {
        Rate::factory()->create(['rate_plan_id' => $setup['plan']->id, 'room_type_id' => $setup['roomType']->id, 'date' => $date, 'amount' => '100.00']);
    }

    $cash = PaymentMethod::factory()->create(['property_id' => $property->id, 'type' => PaymentMethodType::Cash, 'requires_reference' => false]);
    $ota = PaymentMethod::factory()->create(['property_id' => $property->id, 'type' => PaymentMethodType::OtaCollected, 'requires_reference' => false]);
    $cleaning = ChargeItem::factory()->create(['property_id' => $property->id, 'default_amount' => null, 'allow_override' => true]);
    $second = $setup['guest']->replicate()->fill(['full_name' => 'Lim Siew Lan'])->forceFill(['property_id' => $property->id]);
    $second->save();

    $link = app(RecordImportLink::class);
    $link($property, 'room_types', '1', $setup['roomType']);
    $link($property, 'rooms', '21', $setup['rooms'][0]);
    $link($property, 'rooms', '22', $setup['rooms'][1]);
    $link($property, 'rate_plans', '5', $setup['plan']);
    $link($property, 'guests', '11', $setup['guest']);
    $link($property, 'guests', '12', $second);
    $link($property, 'transaction_codes', '31', $cash);
    $link($property, 'transaction_codes', '32', $ota);
    $link($property, 'transaction_codes', '33', $cleaning);

    return [...$setup, 'cash' => $cash, 'ota' => $ota, 'cleaning' => $cleaning, 'admin' => User::factory()->admin()->create()];
}

/**
 * Three business dates (13, 14, 15 Aug 2026, UTC windows) and two bookings: booking 1 is created on the 13th (walk-in,
 * 13 to 15 Aug, 120.00 a night, cash 100.00 on the 13th with receipt 77, a 20.00 cleaning charge, cash 160.00 on the
 * 14th with receipt 78, checked out on the 15th with invoice 9); booking 2 is created on the 14th for a stay that began
 * on the 13th (Booking.com, nights 100.00 and 150.00, checked in on the 14th, OTA collected 250.00 with receipt 79,
 * checked out on the 15th with invoice 10).
 *
 * @param  array<string, list<array<string, string|null>>>  $extra
 * @return array<string, list<array<string, string|null>>>
 */
function wastaysReplayTables(array $extra = []): array
{
    $audit = fn (string $id, string $booking, string $action, string $at): array => [
        'id' => $id, 'auditable_type' => 'Booking', 'auditable_id' => $booking, 'action_type' => $action, 'occurred_at' => $at, 'old_value' => '{}', 'new_value' => '{}',
    ];
    $line = fn (string $id, string $booking, string $category, string $type, string $code, string $amount, string $at, ?string $reference = null): array => [
        'id' => $id, 'source_booking_id' => $booking, 'category' => $category, 'transaction_type' => $type, 'transaction_code_id' => $code, 'amount' => $amount,
        'created_at' => $at, 'description' => 'Invented line', 'reversal_of_transaction_id' => null, 'moved_from_transaction_id' => null, 'metadata' => json_encode($reference === null ? [] : ['reference' => $reference]),
    ];

    return [
        'hotel_business_dates' => [
            ['business_date' => '2026-08-13', 'opened_at' => '2026-08-13T00:00:00.000000Z', 'closed_at' => '2026-08-14T00:00:00.000000Z'],
            ['business_date' => '2026-08-14', 'opened_at' => '2026-08-14T00:00:00.000000Z', 'closed_at' => '2026-08-15T00:00:00.000000Z'],
            ['business_date' => '2026-08-15', 'opened_at' => '2026-08-15T00:00:00.000000Z', 'closed_at' => null],
        ],
        'bookings' => [
            ['id' => '1', 'source' => 'walk_in', 'created_at' => '2026-08-13T02:00:00.000000Z', 'check_in' => '2026-08-13 00:00:00', 'check_out' => '2026-08-15 00:00:00', 'adults' => '2', 'children' => '0', 'reservation_number' => '41'],
            ['id' => '2', 'source' => 'booking_com', 'created_at' => '2026-08-14T03:00:00.000000Z', 'check_in' => '2026-08-13 00:00:00', 'check_out' => '2026-08-15 00:00:00', 'adults' => '1', 'children' => '0', 'reservation_number' => '42'],
        ],
        'booking_rooms' => [
            ['id' => '1', 'booking_id' => '1', 'room_type_id' => '1', 'rate_plan_id' => '5', 'room_id' => '21', 'nightly_rate_snapshot' => json_encode(['2026-08-13' => ['price' => '120.0'], '2026-08-14' => ['price' => '120.0']])],
            ['id' => '2', 'booking_id' => '2', 'room_type_id' => '1', 'rate_plan_id' => '5', 'room_id' => '22', 'nightly_rate_snapshot' => json_encode(['2026-08-13' => ['price' => '100.0'], '2026-08-14' => ['price' => '150.0']])],
        ],
        'booking_guests' => [
            ['id' => '1', 'booking_id' => '1', 'guest_id' => '11', 'role' => 'primary'],
            ['id' => '2', 'booking_id' => '2', 'guest_id' => '12', 'role' => 'primary'],
        ],
        'booking_audit_logs' => [
            $audit('1', '1', 'check_in', '2026-08-13T03:00:00.000000Z'),
            $audit('2', '2', 'check_in', '2026-08-14T03:10:00.000000Z'),
            $audit('3', '1', 'check_out', '2026-08-15T03:00:00.000000Z'),
            $audit('4', '2', 'check_out', '2026-08-15T03:05:00.000000Z'),
        ],
        'folio_transactions' => [
            $line('10', '1', 'cash', 'payment', '31', '100.00', '2026-08-13T04:00:00.000000Z', 'RESIT 1'),
            $line('11', '1', 'other', 'charge', '33', '20.00', '2026-08-13T05:00:00.000000Z'),
            $line('12', '1', 'cash', 'payment', '31', '160.00', '2026-08-14T04:00:00.000000Z'),
            $line('13', '2', 'booking_payment', 'payment', '32', '250.00', '2026-08-14T04:00:00.000000Z'),
        ],
        'receipts' => [
            ['id' => '1', 'folio_transaction_id' => '10', 'deposit_id' => null, 'receipt_number' => '77'],
            ['id' => '2', 'folio_transaction_id' => '13', 'deposit_id' => null, 'receipt_number' => '79'],
            ['id' => '3', 'folio_transaction_id' => '12', 'deposit_id' => null, 'receipt_number' => '78'],
        ],
        'booking_folios' => [['id' => '1', 'booking_id' => '1'], ['id' => '2', 'booking_id' => '2']],
        'invoices' => [['id' => '1', 'booking_folio_id' => '1', 'invoice_number' => '9'], ['id' => '2', 'booking_folio_id' => '2', 'invoice_number' => '10']],
        ...$extra,
    ];
}

/**
 * @param  array<string, list<array<string, string|null>>>  $tables
 * @return array{0: WastaysImportTarget, 1: WastaysDay}
 */
function wastaysReplayTarget(array $setup, array $tables): array
{
    $path = wastaysDayBundleDir($tables, ['hotel_code' => $setup['property']->document_prefix]);
    $target = WastaysImportTarget::resolveForReplay($path, $setup['property']->code, $setup['admin']->email);

    return [$target, new WastaysDay(WastaysBundle::open($path))];
}
