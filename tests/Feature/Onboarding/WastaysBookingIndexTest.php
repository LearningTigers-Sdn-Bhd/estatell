<?php

use App\Services\Onboarding\Wastays\WastaysBookingIndex;
use App\Services\Onboarding\Wastays\WastaysBundle;

it('prices a charged night at its first room line and a night not charged yet at the snapshot', function () {
    $line = fn (string $id, string $date, string $amount, string $at, ?string $reversalOf = null): array => [
        'id' => $id, 'source_booking_id' => '57', 'category' => 'accommodation', 'amount' => $amount, 'posting_date' => $date,
        'created_at' => $at, 'reversal_of_transaction_id' => $reversalOf, 'moved_from_transaction_id' => null, 'metadata' => '{"posting_source":"night_audit"}',
    ];
    $bundle = WastaysBundle::open(wastaysBundleDir([
        'booking_rooms' => [['id' => '1', 'booking_id' => '57', 'nightly_rate_snapshot' => json_encode([
            '2026-09-01' => ['price' => '121.91'], '2026-09-02' => ['price' => '121.91'], '2026-09-03' => ['price' => '121.91'],
        ])]],
        'folio_transactions' => [
            $line('127', '2026-09-01', '192.24', '2026-09-01T16:30:00.000000Z'),
            $line('209', '2026-09-02', '121.91', '2026-09-02T16:30:00.000000Z'),
            $line('300', '2026-09-02', '-121.91', '2026-09-02T17:00:00.000000Z', '209'),
        ],
    ]));

    expect((new WastaysBookingIndex($bundle))->nights('57'))->toBe([
        '2026-09-01' => '192.24',
        '2026-09-02' => '121.91',
        '2026-09-03' => '121.91',
    ]);
});
