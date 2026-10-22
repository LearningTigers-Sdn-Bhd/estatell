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
