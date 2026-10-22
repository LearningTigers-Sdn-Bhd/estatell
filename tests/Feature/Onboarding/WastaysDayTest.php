<?php

use App\Enums\WastaysEventType;
use App\Models\BusinessDate;
use App\Models\ChannelConnection;
use App\Models\ChannelProperty;
use App\Models\Property;
use App\Models\User;
use App\Services\Onboarding\Wastays\WastaysBundle;
use App\Services\Onboarding\Wastays\WastaysDay;
use App\Services\Onboarding\Wastays\WastaysImportTarget;

/** @param array<string, list<array<string, string|null>>> $extra */
function dayBundle(array $extra = []): WastaysDay
{
    $tables = [
        'hotel_business_dates' => [
            ['business_date' => '2026-08-13', 'opened_at' => '2026-08-13T00:00:00.000000Z', 'closed_at' => '2026-08-14T00:00:00.000000Z'],
            ['business_date' => '2026-08-14', 'opened_at' => '2026-08-14T00:00:00.000000Z', 'closed_at' => null],
        ],
        'bookings' => [
            ['id' => '1', 'created_at' => '2026-08-13T02:00:00.000000Z'],
            ['id' => '2', 'created_at' => '2026-08-14T03:00:00.000000Z'],
        ],
        ...$extra,
    ];

    foreach ($tables as $name => $rows) {
        $columns = array_values(array_unique(array_merge(...array_map('array_keys', $rows))));
        $tables[$name] = array_map(fn (array $row): array => array_merge(array_fill_keys($columns, null), $row), $rows);
    }

    return new WastaysDay(WastaysBundle::open(wastaysBundleDir($tables)));
}

it('puts a row on the day whose window holds its created_at, whatever its posting_date says', function () {
    $day = dayBundle(['folio_transactions' => [
        ['id' => '10', 'category' => 'cash', 'transaction_type' => 'payment', 'created_at' => '2026-08-14T05:00:00.000000Z', 'posting_date' => '2026-08-13', 'source_booking_id' => '1', 'reversal_of_transaction_id' => null, 'moved_from_transaction_id' => null, 'metadata' => '{}'],
    ]]);

    expect($day->dates())->toBe(['2026-08-13', '2026-08-14'])
        ->and(array_map(fn ($event) => $event->sourceTable.':'.$event->sourceId, $day->timeline('2026-08-13')))->toBe(['bookings:1'])
        ->and(array_map(fn ($event) => $event->sourceTable.':'.$event->sourceId, $day->timeline('2026-08-14')))->toBe(['bookings:2', 'folio_transactions:10'])
        ->and($day->unassigned())->toBe([]);
});

it('sorts by time and breaks a tie in the documented order', function () {
    $same = '2026-08-13T04:00:00.000000Z';
    $day = dayBundle([
        'booking_audit_logs' => [
            ['id' => '1', 'auditable_type' => 'Booking', 'auditable_id' => '1', 'action_type' => 'check_out', 'occurred_at' => $same, 'old_value' => '{}', 'new_value' => '{}'],
            ['id' => '2', 'auditable_type' => 'Booking', 'auditable_id' => '1', 'action_type' => 'check_in', 'occurred_at' => $same, 'old_value' => '{}', 'new_value' => '{}'],
            ['id' => '3', 'auditable_type' => 'Booking', 'auditable_id' => '1', 'action_type' => 'cancel', 'occurred_at' => $same, 'old_value' => '{}', 'new_value' => '{}'],
        ],
        'folio_transactions' => [
            ['id' => '10', 'category' => 'cash', 'transaction_type' => 'payment', 'created_at' => $same, 'source_booking_id' => '1', 'metadata' => '{}'],
            ['id' => '11', 'category' => 'other', 'transaction_type' => 'charge', 'created_at' => $same, 'source_booking_id' => '1', 'metadata' => '{}'],
            ['id' => '12', 'category' => 'correction', 'transaction_type' => 'adjustment', 'created_at' => $same, 'source_booking_id' => '1', 'reversal_of_transaction_id' => '11', 'metadata' => '{}'],
            ['id' => '13', 'category' => 'early_departure_charge', 'transaction_type' => 'charge', 'created_at' => $same, 'source_booking_id' => '1', 'metadata' => '{}'],
        ],
        'deposits' => [['id' => '5', 'booking_id' => '1', 'received_at' => '2026-08-13T03:59:59.000000Z']],
    ]);

    expect(array_map(fn ($event) => $event->type, $day->timeline('2026-08-13')))->toBe([
        WastaysEventType::Create,
        WastaysEventType::Deposit,
        WastaysEventType::CheckIn,
        WastaysEventType::Charge,
        WastaysEventType::Payment,
        WastaysEventType::Reversal,
        WastaysEventType::StayChange,
        WastaysEventType::EarlyDeparture,
        WastaysEventType::CheckOut,
    ]);
});

it('leaves out what the audit posts and the audit rows that change nothing we replay', function () {
    $audit = fn (string $id, string $source, string $category = 'accommodation', ?string $movedFrom = null) => [
        'id' => $id, 'category' => $category, 'transaction_type' => 'charge', 'created_at' => '2026-08-13T05:00:00.000000Z', 'source_booking_id' => '1',
        'moved_from_transaction_id' => $movedFrom, 'metadata' => json_encode(['posting_source' => $source]),
    ];
    $day = dayBundle([
        'folio_transactions' => [
            $audit('20', 'night_audit'),
            $audit('21', 'catch_up'),
            $audit('22', 'audit_blocker_resolution'),
            $audit('23', 'night_audit', 'tax'),
            $audit('24', 'historical_nightly_charge_repair', movedFrom: '20'),
            $audit('25', 'historical_nightly_charge_repair'),
            $audit('26', 'staff'),
        ],
        'booking_audit_logs' => [
            ['id' => '1', 'auditable_type' => 'Booking', 'auditable_id' => '1', 'action_type' => 'update', 'occurred_at' => '2026-08-13T06:00:00.000000Z', 'old_value' => '{"total_amount":"1"}', 'new_value' => '{"total_amount":"2"}'],
            ['id' => '2', 'auditable_type' => 'Booking', 'auditable_id' => '1', 'action_type' => 'update', 'occurred_at' => '2026-08-13T06:00:01.000000Z', 'old_value' => '{"check_out":"2026-08-15"}', 'new_value' => '{"check_out":"2026-08-16"}'],
            ['id' => '3', 'auditable_type' => 'Booking', 'auditable_id' => '1', 'action_type' => 'status_change', 'occurred_at' => '2026-08-13T06:00:02.000000Z', 'old_value' => '{}', 'new_value' => '{}'],
            ['id' => '4', 'auditable_type' => 'Booking', 'auditable_id' => '1', 'action_type' => 'create', 'occurred_at' => '2026-08-13T02:00:00.000000Z', 'old_value' => '{}', 'new_value' => '{}'],
        ],
    ]);

    expect(array_map(fn ($event) => $event->sourceTable.':'.$event->sourceId, $day->timeline('2026-08-13')))
        ->toBe(['bookings:1', 'folio_transactions:24', 'folio_transactions:26', 'booking_audit_logs:2']);
});

it('reports a row that falls in no window', function () {
    $day = dayBundle(['folio_transactions' => [
        ['id' => '30', 'category' => 'cash', 'transaction_type' => 'payment', 'created_at' => '2026-08-12T23:00:00.000000Z', 'source_booking_id' => '1', 'reversal_of_transaction_id' => null, 'moved_from_transaction_id' => null, 'metadata' => '{}'],
    ]]);

    expect(array_map(fn ($event) => $event->sourceId, $day->unassigned()))->toBe(['30']);
});

it('refuses a day while a channel is connected, when the business dates differ and when unread tables are not empty', function () {
    $property = Property::factory()->live()->create();
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-08-14']);
    $day = dayBundle(['payment_transactions' => [['id' => '1']], 'refund_requests' => [['id' => '1']]]);

    expect($day->refusals($property, '2026-08-14'))->toHaveCount(2)
        ->and($day->refusals($property, '2026-08-13'))->toHaveCount(3);

    ChannelConnection::factory()->create(['channel_property_id' => ChannelProperty::factory()->create(['property_id' => $property->id])->id]);

    expect(implode(' ', $day->refusals($property, '2026-08-14')))->toContain('A channel is connected');
    expect(dayBundle()->refusals($property, '2026-08-13'))->toHaveCount(2)
        ->and(dayBundle()->refusals($property, '2026-08-14'))->toHaveCount(1);
});

it('accepts a clean day', function () {
    $property = Property::factory()->live()->create();
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-08-14']);

    expect(dayBundle()->refusals($property, '2026-08-14'))->toBe([]);
});

it('resolves the replay target for a live property and refuses one that is not live', function () {
    $admin = User::factory()->admin()->create();
    $live = Property::factory()->live()->create(['document_prefix' => 'GEH']);
    $onboarding = Property::factory()->onboarding()->create(['document_prefix' => 'ONB']);
    $bundle = wastaysBundleDir(['hotel_business_dates' => [['business_date' => '2026-08-13']]]);
    $other = wastaysBundleDir(['hotel_business_dates' => [['business_date' => '2026-08-13']]], ['hotel_code' => 'ONB']);

    expect(WastaysImportTarget::resolveForReplay($bundle, $live->code, $admin->email)->property->is($live))->toBeTrue()
        ->and(fn () => WastaysImportTarget::resolveForReplay($other, $onboarding->code, $admin->email))->toThrow(InvalidArgumentException::class, 'not live')
        ->and(fn () => WastaysImportTarget::resolve($bundle, $live->code, $admin->email))->toThrow(InvalidArgumentException::class, 'already gone live');
});
