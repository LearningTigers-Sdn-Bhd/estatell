<?php

use App\Actions\NightAudit\RunNightAudit;
use App\Actions\Onboarding\Wastays\RecordImportLink;
use App\Actions\Onboarding\Wastays\ReplayStopped;
use App\Actions\Onboarding\Wastays\ReplayWastaysDay;
use App\Actions\Reservations\CreateBookingSource;
use App\Actions\Reservations\SeedBookingSources;
use App\Enums\FolioStatus;
use App\Enums\PaymentPurpose;
use App\Enums\UnitStatus;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\ImportLink;
use App\Models\OtaChannel;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Services\NightAudit\PlannedDocumentNumbers;
use App\Services\Onboarding\Wastays\WastaysDay;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/../Support/WastaysReplaySetup.php';

function replayDay(array $setup, WastaysDay $day, $target, string $date)
{
    return app(ReplayWastaysDay::class)($target, $day, $date);
}

function auditDay(array $setup): void
{
    expect(app(RunNightAudit::class)($setup['property'])->isRefused())->toBeFalse();
}

it('replays a booking, its check-in, charge and payments with the wastays numbers, and a second run writes nothing', function () {
    $setup = wastaysReplaySetup();
    [$target, $day] = wastaysReplayTarget($setup, wastaysReplayTables());
    $prefix = $setup['property']->document_prefix;

    $summary = replayDay($setup, $day, $target, '2026-08-13');
    $reservation = Reservation::query()->where('property_id', $setup['property']->id)->sole();
    $unit = $reservation->units()->sole();

    expect($summary->counts)->toMatchArray(['bookings created' => 1, 'check-ins' => 1, 'charges posted' => 1, 'payments recorded' => 1])
        ->and($reservation->reference)->toBe("{$prefix}-26100041")
        ->and($reservation->bookingSource->name)->toBe('Walk-in')
        ->and($unit->status)->toBe(UnitStatus::CheckedIn)
        ->and($unit->nights()->pluck('override_amount')->all())->toBe(['120.00', '120.00'])
        ->and($unit->nights()->pluck('room_id')->unique()->all())->toBe([$setup['rooms'][0]->id])
        ->and(Payment::query()->sole()->receipt_number)->toBe("{$prefix}-26500077")
        ->and(Payment::query()->sole()->purpose)->toBe(PaymentPurpose::Settlement)
        ->and(Payment::query()->sole()->reference)->toBe('RESIT 1')
        ->and(app(PlannedDocumentNumbers::class)->pending())->toBe([]);

    $lines = FolioLine::query()->count();
    $links = ImportLink::query()->count();
    $again = replayDay($setup, $day, $target, '2026-08-13');

    expect(FolioLine::query()->count())->toBe($lines)
        ->and(ImportLink::query()->count())->toBe($links)
        ->and(Reservation::query()->count())->toBe(1)
        ->and($again->counts)->not->toHaveKey('bookings created');
});

it('replays three days: a backdated booking with night prices, an OTA payment and both check-outs with the wastays invoices', function () {
    $setup = wastaysReplaySetup();
    [$target, $day] = wastaysReplayTarget($setup, wastaysReplayTables());
    $prefix = $setup['property']->document_prefix;

    replayDay($setup, $day, $target, '2026-08-13');
    auditDay($setup);
    $second = replayDay($setup, $day, $target, '2026-08-14');
    $backdated = Reservation::query()->where('reference', "{$prefix}-26100042")->sole()->units()->sole();

    expect($second->counts)->toMatchArray(['bookings created' => 1, 'backdated booking checked in as of its arrival' => 1, 'payments recorded' => 2])
        ->and($backdated->status)->toBe(UnitStatus::CheckedIn)
        ->and($backdated->checked_in_at->toDateString())->toBe('2026-08-13')
        ->and($backdated->nights()->orderBy('date')->pluck('override_amount')->all())->toBe(['100.00', '150.00'])
        ->and($backdated->reservation->bookingSource->name)->toBe('Booking.com')
        ->and(Payment::query()->where('receipt_number', "{$prefix}-26500079")->sole()->purpose)->toBe(PaymentPurpose::Settlement);

    auditDay($setup);
    $third = replayDay($setup, $day, $target, '2026-08-15');

    expect($third->counts)->toMatchArray(['check-outs' => 2])
        ->and(ReservationUnit::query()->where('status', UnitStatus::CheckedOut)->count())->toBe(2)
        ->and(Folio::query()->where('status', FolioStatus::Closed)->orderBy('id')->pluck('invoice_number')->all())->toBe(["{$prefix}-26700009", "{$prefix}-26700010"])
        ->and(Payment::query()->pluck('receipt_number')->sort()->values()->all())->toBe(["{$prefix}-26500077", "{$prefix}-26500078", "{$prefix}-26500079"]);
});

it('stops a day and keeps nothing when a row cannot be replayed, and restores the clock', function () {
    $setup = wastaysReplaySetup();
    $tables = wastaysReplayTables();
    $tables['booking_guests'][1]['guest_id'] = '99';
    [$target, $day] = wastaysReplayTarget($setup, $tables);
    $before = Carbon::getTestNow();

    replayDay($setup, $day, $target, '2026-08-13');
    auditDay($setup);

    expect(fn () => replayDay($setup, $day, $target, '2026-08-14'))->toThrow(ReplayStopped::class, 'guests 99 is not in estatell')
        ->and(Reservation::query()->count())->toBe(1)
        ->and(app(PlannedDocumentNumbers::class)->pending())->toBe([])
        ->and(Carbon::getTestNow()->equalTo($before))->toBeTrue()
        ->and(config('queue.default'))->not->toBe('null');
});

it('refuses a date that is not the estatell business date', function () {
    $setup = wastaysReplaySetup();
    [$target, $day] = wastaysReplayTarget($setup, wastaysReplayTables());

    expect(fn () => replayDay($setup, $day, $target, '2026-08-14'))->toThrow(ReplayStopped::class, 'The estatell business date is 2026-08-13')
        ->and(Reservation::query()->count())->toBe(0);
});

it('uses a wastays number that is not above the last number as a stop', function () {
    $setup = wastaysReplaySetup();
    $tables = wastaysReplayTables();
    $tables['bookings'][1]['reservation_number'] = '41';
    [$target, $day] = wastaysReplayTarget($setup, $tables);

    replayDay($setup, $day, $target, '2026-08-13');
    auditDay($setup);

    expect(fn () => replayDay($setup, $day, $target, '2026-08-14'))->toThrow(ReplayStopped::class, 'not above the last issued number');
});

it('sends no mail while a day runs', function () {
    $setup = wastaysReplaySetup();
    [$target, $day] = wastaysReplayTarget($setup, wastaysReplayTables());
    Mail::fake();

    replayDay($setup, $day, $target, '2026-08-13');

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

it('records a security deposit with its wastays receipt number', function () {
    $setup = wastaysReplaySetup();
    $tables = wastaysReplayTables([
        'deposits' => [['id' => '5', 'booking_id' => '1', 'amount' => '50.00', 'received_at' => '2026-08-13T03:30:00.000000Z', 'external_reference' => 'DEP-1']],
    ]);
    $tables['receipts'][] = ['id' => '4', 'folio_transaction_id' => null, 'deposit_id' => '5', 'receipt_number' => '76'];
    [$target, $day] = wastaysReplayTarget($setup, $tables);
    $prefix = $setup['property']->document_prefix;

    $summary = replayDay($setup, $day, $target, '2026-08-13');
    $deposit = Payment::query()->where('purpose', PaymentPurpose::SecurityDeposit)->sole();

    expect($summary->counts)->toMatchArray(['deposits recorded' => 1])
        ->and($deposit->receipt_number)->toBe("{$prefix}-26500076")
        ->and($deposit->amount)->toBe('50.00')
        ->and($deposit->reference)->toBe('DEP-1');
});

it('checks a guest in on the arrival day when wastays checked in earlier', function () {
    $setup = wastaysReplaySetup();
    $tables = wastaysReplayTables();
    $tables['bookings'] = [array_merge($tables['bookings'][0], ['check_in' => '2026-08-14 00:00:00'])];
    $tables['booking_rooms'] = [array_merge($tables['booking_rooms'][0], ['nightly_rate_snapshot' => json_encode(['2026-08-14' => ['price' => '120.0']])])];
    $tables['booking_guests'] = [$tables['booking_guests'][0]];
    $tables['booking_audit_logs'] = [$tables['booking_audit_logs'][0], $tables['booking_audit_logs'][2]];
    $tables['folio_transactions'] = [$tables['folio_transactions'][2]];
    $tables['receipts'] = [$tables['receipts'][2]];
    [$target, $day] = wastaysReplayTarget($setup, $tables);

    $first = replayDay($setup, $day, $target, '2026-08-13');
    $unit = ReservationUnit::query()->sole();

    expect($first->counts)->toMatchArray(['check-ins before the arrival date moved' => 1])
        ->and($unit->status)->toBe(UnitStatus::Confirmed);

    auditDay($setup);
    $second = replayDay($setup, $day, $target, '2026-08-14');

    expect($second->counts)->toMatchArray(['check-in moved to the arrival day' => 1, 'payments recorded' => 1])
        ->and($unit->refresh()->status)->toBe(UnitStatus::CheckedIn);
});

it('uses the wastays receipt number as the reference when the method needs one and wastays saved none', function () {
    $setup = wastaysReplaySetup();
    $setup['cash']->update(['requires_reference' => true]);
    $tables = wastaysReplayTables();
    $tables['folio_transactions'][0]['metadata'] = '{}';
    $tables['receipts'][0]['public_number'] = 'GEH-26500077';
    [$target, $day] = wastaysReplayTarget($setup, $tables);

    replayDay($setup, $day, $target, '2026-08-13');

    expect(Payment::query()->sole()->reference)->toBe('wastays GEH-26500077');
});

it('places the Sand Bay booking sources, with the travel agent company from the companies import', function (string $source, string $name, string $type) {
    $setup = wastaysReplaySetup();
    app(SeedBookingSources::class)($setup['property']);
    app(CreateBookingSource::class)($setup['property'], $setup['admin'], ['name' => 'Email', 'type' => 'direct']);
    OtaChannel::factory()->other()->create();
    $tables = wastaysReplayTables();
    $tables['bookings'][0]['source'] = $source;
    [$target, $day] = wastaysReplayTarget($setup, $tables);

    replayDay($setup, $day, $target, '2026-08-13');

    $source = Reservation::query()->where('property_id', $setup['property']->id)->sole()->bookingSource;

    expect($source->name)->toBe($name)->and($source->type->value)->toBe($type);
})->with([
    'email' => ['email', 'Email', 'direct'],
    'travel agent' => ['travel_agent', 'Company / Travel agent', 'company'],
    'internal' => ['internal', 'Internal', 'direct'],
    'ota' => ['ota', 'OTA', 'ota'],
]);

it('stops when a booking belongs to a company that was not imported, and uses the company when it was', function () {
    $setup = wastaysReplaySetup();
    app(SeedBookingSources::class)($setup['property']);
    $tables = wastaysReplayTables();
    $tables['bookings'][0]['source'] = 'travel_agent';
    $tables['bookings'][0]['hotel_corporate_account_id'] = '8';
    [$target, $day] = wastaysReplayTarget($setup, $tables);

    expect(fn () => replayDay($setup, $day, $target, '2026-08-13'))->toThrow(ReplayStopped::class, 'the company 8 is not in estatell');

    $company = Company::factory()->for($setup['property'])->create();
    app(RecordImportLink::class)($setup['property'], 'hotel_corporate_accounts', '8', $company);
    replayDay($setup, $day, $target, '2026-08-13');

    expect(Reservation::query()->where('property_id', $setup['property']->id)->sole()->company_id)->toBe($company->id);
});
