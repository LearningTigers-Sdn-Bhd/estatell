<?php

use App\Models\Reservation;
use Illuminate\Testing\PendingCommand;

require_once __DIR__.'/../Support/WastaysReplaySetup.php';

/**
 * Bundle tables with the audits of the 13th and 14th and their financial summaries. The 13th: the audit posts the night
 * of booking 1 (120.00) and cash 100.00 came in. The 14th: the audit posts booking 1 (120.00) and booking 2 (150.00),
 * the check-in of booking 2 posts its missed night of the 13th (100.00), and 160.00 plus 250.00 came in.
 *
 * @param  array<string, string>  $second  figures of the 14th that replace the matching ones
 * @return array<string, list<array<string, string|null>>>
 */
function commandTables(array $second = []): array
{
    $summary = fn (string $id, string $audit, array $figures): array => [
        'id' => $id, 'night_audit_id' => $audit, 'room_revenue' => '0.00', 'tax_revenue' => '0.00', 'payments_total' => '0.00',
        'refunds_total' => '0.00', 'adjustments_total' => '0.00', 'no_show_charges' => '0.00', ...$figures,
    ];

    return wastaysReplayTables([
        'night_audits' => [['id' => '1', 'business_date' => '2026-08-13'], ['id' => '2', 'business_date' => '2026-08-14']],
        'night_audit_financial_summaries' => [
            $summary('1', '1', ['room_revenue' => '120.00', 'payments_total' => '100.00']),
            $summary('2', '2', ['room_revenue' => '370.00', 'payments_total' => '410.00', ...$second]),
        ],
    ]);
}

function replayCommand(array $setup, array $tables, array $options = []): PendingCommand
{
    $path = wastaysDayBundleDir($tables, ['hotel_code' => $setup['property']->document_prefix]);

    return test()->artisan('wastays:replay', ['bundle' => $path, 'property' => $setup['property']->code, '--actor' => $setup['admin']->email, ...$options]);
}

it('replays every day with a night audit and compares each with its wastays figures', function () {
    $setup = wastaysReplaySetup();

    replayCommand($setup, commandTables())
        ->expectsOutputToContain('2 day(s) replayed')
        ->assertSuccessful();

    expect(Reservation::query()->count())->toBe(2);
});

it('writes nothing in a dry run', function () {
    $setup = wastaysReplaySetup();

    replayCommand($setup, commandTables(), ['--dry-run' => true])
        ->expectsOutputToContain('Dry run: nothing was written.')
        ->assertSuccessful();

    expect(Reservation::query()->count())->toBe(0);
});

it('stops at the first day that does not match, shows the differences, exits non-zero and keeps the days before it', function () {
    $setup = wastaysReplaySetup();

    replayCommand($setup, commandTables(['payments_total' => '999.00']))
        ->expectsOutputToContain('Stopped at 2026-08-14')
        ->expectsOutputToContain('payments_total')
        ->assertFailed();

    expect(Reservation::query()->count())->toBe(1);
});

it('replays up to the date given with --until', function () {
    $setup = wastaysReplaySetup();

    replayCommand($setup, commandTables(), ['--until' => '2026-08-13'])->assertSuccessful();

    expect(Reservation::query()->count())->toBe(1);
});

it('resumes from the open business date and writes nothing twice', function () {
    $setup = wastaysReplaySetup();
    replayCommand($setup, commandTables(), ['--until' => '2026-08-13'])->assertSuccessful();

    replayCommand($setup, commandTables())->expectsOutputToContain('1 day(s) replayed')->assertSuccessful();

    expect(Reservation::query()->count())->toBe(2);
});

it('refuses a property that is not live', function () {
    $setup = wastaysReplaySetup();
    $setup['property']->forceFill(['status' => 'onboarding'])->save();

    replayCommand($setup, commandTables())->expectsOutputToContain('not live')->assertFailed();
});

it('stops and writes nothing of a day that cannot be replayed', function () {
    $setup = wastaysReplaySetup();
    $tables = commandTables();
    $tables['booking_guests'][0]['guest_id'] = '99';

    replayCommand($setup, $tables)->expectsOutputToContain('guests 99 is not in estatell')->assertFailed();

    expect(Reservation::query()->count())->toBe(0);
});
