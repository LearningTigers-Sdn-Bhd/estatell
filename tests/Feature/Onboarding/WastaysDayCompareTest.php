<?php

use App\Enums\PaymentPurpose;
use App\Enums\SystemTransactionCode;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\TransactionCode;
use App\Services\Onboarding\Wastays\WastaysBundle;
use App\Services\Onboarding\Wastays\WastaysDay;
use App\Services\Onboarding\Wastays\WastaysDayCompare;

require_once __DIR__.'/../Support/WastaysReplaySetup.php';

it('leaves security deposits out of the payment total, as wastays does', function () {
    $folio = Folio::factory()->create();
    Payment::factory()->create(['folio_id' => $folio->id, 'amount' => '120.00']);
    Payment::factory()->create(['folio_id' => $folio->id, 'amount' => '50.00', 'purpose' => PaymentPurpose::SecurityDeposit]);

    $figures = app(WastaysDayCompare::class)->estatell($folio->property, '2026-11-02');

    expect($figures['payments_total'])->toBe('120.00');
});

it('adds the catch-up nights wastays posted in the window to the wastays room and tax figures', function () {
    $line = fn (string $id, string $category, string $amount, string $at, ?string $audit = null, string $source = 'catch_up'): array => [
        'id' => $id, 'category' => $category, 'amount' => $amount, 'created_at' => $at, 'night_audit_id' => $audit, 'metadata' => json_encode(['posting_source' => $source]),
    ];
    $path = wastaysDayBundleDir([
        'hotel_business_dates' => [
            ['business_date' => '2026-08-13', 'opened_at' => '2026-08-13T00:00:00.000000Z', 'closed_at' => '2026-08-14T00:00:00.000000Z'],
            ['business_date' => '2026-08-14', 'opened_at' => '2026-08-14T00:00:00.000000Z', 'closed_at' => null],
        ],
        'night_audits' => [['id' => '1', 'business_date' => '2026-08-13']],
        'night_audit_financial_summaries' => [[
            'id' => '1', 'night_audit_id' => '1', 'room_revenue' => '120.00', 'tax_revenue' => '9.60', 'payments_total' => '0.00',
            'refunds_total' => '0.00', 'adjustments_total' => '0.00', 'no_show_charges' => '0.00',
        ]],
        'folio_transactions' => [
            $line('1', 'accommodation', '100.00', '2026-08-13T05:00:00.000000Z'),
            $line('2', 'tax', '8.00', '2026-08-13T05:00:00.000000Z'),
            $line('3', 'accommodation', '120.00', '2026-08-13T16:00:00.000000Z', '1', 'night_audit'),
            $line('4', 'accommodation', '90.00', '2026-08-14T05:00:00.000000Z'),
        ],
    ]);
    $bundle = WastaysBundle::open($path);

    $figures = app(WastaysDayCompare::class)->wastays($bundle, new WastaysDay($bundle), '2026-08-13');

    expect($figures['room_revenue'])->toBe('220.00')
        ->and($figures['tax_revenue'])->toBe('17.60');
});

it('leaves early departure fees out of the room revenue, as wastays does', function () {
    $folio = Folio::factory()->create();
    $room = TransactionCode::factory()->for($folio->property)->system(SystemTransactionCode::RoomRevenue)->create();
    $early = TransactionCode::factory()->for($folio->property)->system(SystemTransactionCode::EarlyDepartureRevenue)->create();
    FolioLine::factory()->create(['folio_id' => $folio->id, 'transaction_code_id' => $room->id]);
    FolioLine::factory()->create(['folio_id' => $folio->id, 'transaction_code_id' => $early->id, 'amount' => '112.58']);

    $figures = app(WastaysDayCompare::class)->estatell($folio->property, '2026-11-02');

    expect($figures['room_revenue'])->toBe('100.00');
});

it('adds the fee of a same-day leaver and the tax of a fee dated to an earlier night to the wastays figures', function () {
    $line = fn (string $id, string $booking, string $category, string $amount, string $postingDate): array => [
        'id' => $id, 'source_booking_id' => $booking, 'category' => $category, 'amount' => $amount, 'posting_date' => $postingDate,
        'created_at' => '2026-08-13T05:00:00.000000Z', 'night_audit_id' => null, 'metadata' => json_encode(['posting_source' => 'early_departure']),
    ];
    $path = wastaysDayBundleDir([
        'hotel_business_dates' => [['business_date' => '2026-08-13', 'opened_at' => '2026-08-13T00:00:00.000000Z', 'closed_at' => '2026-08-14T00:00:00.000000Z']],
        'night_audits' => [['id' => '1', 'business_date' => '2026-08-13']],
        'night_audit_financial_summaries' => [[
            'id' => '1', 'night_audit_id' => '1', 'room_revenue' => '0.00', 'tax_revenue' => '8.00', 'payments_total' => '0.00',
            'refunds_total' => '0.00', 'adjustments_total' => '0.00', 'no_show_charges' => '0.00',
        ]],
        'bookings' => [
            ['id' => '1', 'check_in' => '2026-08-13 00:00:00', 'check_out' => '2026-08-13 00:00:00'],
            ['id' => '2', 'check_in' => '2026-08-10 00:00:00', 'check_out' => '2026-08-12 00:00:00'],
        ],
        'folio_transactions' => [
            $line('1', '1', 'early_departure_charge', '100.00', '2026-08-13'),
            $line('2', '1', 'tax', '8.00', '2026-08-13'),
            $line('3', '2', 'early_departure_charge', '50.00', '2026-08-12'),
            $line('4', '2', 'tax', '4.00', '2026-08-12'),
        ],
    ]);
    $bundle = WastaysBundle::open($path);

    $figures = app(WastaysDayCompare::class)->wastays($bundle, new WastaysDay($bundle), '2026-08-13');

    expect($figures['room_revenue'])->toBe('100.00')
        ->and($figures['tax_revenue'])->toBe('12.00');
});

it('compares a day with its summary as the audit wrote it and counts a later change on the day it was made', function () {
    $summary = fn (string $id, string $audit, string $room, string $changelog = '[]'): array => [
        'id' => $id, 'night_audit_id' => $audit, 'room_revenue' => $room, 'tax_revenue' => '0.00', 'payments_total' => '0.00',
        'refunds_total' => '0.00', 'adjustments_total' => '0.00', 'no_show_charges' => '0.00', 'changelog' => $changelog,
    ];
    $change = json_encode([[
        'timestamp' => '2026-08-14T10:00:00.000+08:00',
        'previous_values' => ['room_revenue' => '100.0', 'adjustments_total' => '0.0'],
        'new_values' => ['room_revenue' => '150.0', 'adjustments_total' => '-20.0'],
    ]]);
    $path = wastaysDayBundleDir([
        'hotel_business_dates' => [
            ['business_date' => '2026-08-13', 'opened_at' => '2026-08-13T00:00:00.000000Z', 'closed_at' => '2026-08-14T00:00:00.000000Z'],
            ['business_date' => '2026-08-14', 'opened_at' => '2026-08-14T00:00:00.000000Z', 'closed_at' => '2026-08-15T00:00:00.000000Z'],
        ],
        'night_audits' => [['id' => '1', 'business_date' => '2026-08-13'], ['id' => '2', 'business_date' => '2026-08-14']],
        'night_audit_financial_summaries' => [$summary('1', '1', '150.00', $change), $summary('2', '2', '200.00')],
        'folio_transactions' => [['id' => '1', 'category' => 'cash', 'amount' => '0.00', 'created_at' => '2026-08-13T05:00:00.000000Z', 'night_audit_id' => null, 'metadata' => '{}']],
    ]);
    $bundle = WastaysBundle::open($path);
    $compare = app(WastaysDayCompare::class);

    $first = $compare->wastays($bundle, new WastaysDay($bundle), '2026-08-13');
    $second = $compare->wastays($bundle, new WastaysDay($bundle), '2026-08-14');

    expect($first['room_revenue'])->toBe('100.00')
        ->and($second['room_revenue'])->toBe('250.00')
        ->and($second['adjustments_total'])->toBe('-20.00');
});
