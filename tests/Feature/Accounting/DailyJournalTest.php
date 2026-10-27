<?php

use App\Actions\Accounting\PushDailyJournal;
use App\Actions\NightAudit\RunNightAudit;
use App\Enums\AccountingSyncStatus;
use App\Enums\SystemTransactionCode;
use App\Features\AccountingIntegration;
use App\Jobs\PushDailyJournalJob;
use App\Models\AccountingConnection;
use App\Models\BusinessDate;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\TransactionCode;
use App\Services\Accounting\AccountingException;
use App\Services\Accounting\BuildDailyJournal;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;

require_once __DIR__.'/../Support/AccountingSetup.php';

it('builds a balanced journal with the unpaid balance on the guest ledger account', function () {
    ['property' => $property, 'connection' => $connection, 'date' => $date] = accountingSetup();

    $journal = app(BuildDailyJournal::class)->forDate($property, $connection, $date);

    $lines = collect($journal->lines)->keyBy('accountCode');

    expect($journal->reference)->toBe("EST-{$property->code}-2026-10-12")
        ->and($lines['500-000']->credit)->toBe('100.00')
        ->and($lines['600-000']->credit)->toBe('6.00')
        ->and($lines['100-000']->debit)->toBe('50.00')
        ->and($lines['300-000']->debit)->toBe('56.00')
        ->and(collect($journal->lines)->sum(fn ($line) => (float) $line->debit))->toBe(collect($journal->lines)->sum(fn ($line) => (float) $line->credit));
});

it('ignores a reversed line pair, migrated lines and other dates', function () {
    ['property' => $property, 'connection' => $connection, 'date' => $date, 'folio' => $folio] = accountingSetup();
    $room = TransactionCode::query()->where('property_id', $property->id)->where('system_key', SystemTransactionCode::RoomRevenue->value)->firstOrFail();
    FolioLine::factory()->create(['folio_id' => $folio->id, 'transaction_code_id' => $room->id, 'amount' => '999.00', 'unit_amount' => '999.00', 'posting_date' => '2026-10-12', 'migrated' => true]);
    FolioLine::factory()->create(['folio_id' => $folio->id, 'transaction_code_id' => $room->id, 'amount' => '777.00', 'unit_amount' => '777.00', 'posting_date' => '2026-10-11']);

    $journal = app(BuildDailyJournal::class)->forDate($property, $connection, $date);

    expect(collect($journal->lines)->keyBy('accountCode')['500-000']->credit)->toBe('100.00');
});

it('refuses to build when a code with an amount has no account', function () {
    ['property' => $property, 'connection' => $connection, 'date' => $date] = accountingSetup(mapped: false);

    app(BuildDailyJournal::class)->forDate($property, $connection, $date);
})->throws(AccountingException::class, 'No accounting account is set for');

it('returns nothing for a date without money lines', function () {
    ['property' => $property, 'connection' => $connection] = accountingSetup();
    $empty = BusinessDate::factory()->closed()->create(['property_id' => $property->id, 'date' => '2026-10-13']);

    expect(app(BuildDailyJournal::class)->forDate($property, $connection, $empty))->toBeNull();
});

it('pushes a closed date to QNE once and does not push it twice', function () {
    fakeQne();
    ['property' => $property, 'date' => $date] = accountingSetup();

    $first = app(PushDailyJournal::class)($property, $date);
    $second = app(PushDailyJournal::class)($property, $date);

    expect($first->status)->toBe(AccountingSyncStatus::Pushed)
        ->and($first->external_id)->toBe('journal-1')
        ->and($second->is($first))->toBeTrue()
        ->and(journalPosts())->toBe(1);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api/Journals/Create')
        && ($request['documentStatus'] ?? null) !== 0
        && count($request['details']) === 4
        && filled($request['details'][0]['account']['id'])
        && filled($request['details'][0]['account']['code'])
        && $request['referenceNo'] === "EST-{$property->code}-2026-10-12");
});

it('saves a QNE refusal as Failed and pushes again on retry', function () {
    fakeQne(refuseFirstSignIn: true);
    ['property' => $property, 'connection' => $connection, 'date' => $date] = accountingSetup();

    $failed = app(PushDailyJournal::class)($property, $date);

    expect($failed->status)->toBe(AccountingSyncStatus::Failed)
        ->and($failed->error)->toContain('QNE refused')
        ->and($failed->attempts)->toBe(1)
        ->and($connection->refresh()->last_error)->not->toBeNull();

    $pushed = app(PushDailyJournal::class)($property, $date);

    expect($pushed->status)->toBe(AccountingSyncStatus::Pushed)
        ->and($pushed->attempts)->toBe(2)
        ->and($pushed->error)->toBeNull()
        ->and($connection->refresh()->last_error)->toBeNull();
});

it('fails the sync when an account is missing in QNE and sends no journal', function () {
    Http::fake([
        '*/api/Auth/Connect*' => Http::response(['code' => '0000', 'data' => ['token' => 'jwt']]),
        '*/api/AccountCodes/Leaf/Query*' => Http::response(['code' => '0000', 'data' => ['count' => 0, 'value' => []]]),
    ]);
    ['property' => $property, 'date' => $date] = accountingSetup();

    $sync = app(PushDailyJournal::class)($property, $date);

    expect($sync->status)->toBe(AccountingSyncStatus::Failed)
        ->and($sync->error)->toContain('do not exist in the accounting software')
        ->and(journalPosts())->toBe(0);
});

it('fails the sync, and does not throw, when QNE drops the connection after sign-in', function () {
    Http::fake([
        '*/api/Auth/Connect*' => Http::response(['code' => '0000', 'data' => ['token' => 'jwt']]),
        '*/api/AccountCodes/Leaf/Query*' => fn () => throw new ConnectionException('timed out'),
    ]);
    ['property' => $property, 'date' => $date] = accountingSetup();

    $sync = app(PushDailyJournal::class)($property, $date);

    expect($sync->status)->toBe(AccountingSyncStatus::Failed)
        ->and($sync->error)->toContain('QNE could not be reached');
});

it('marks a date with no money lines as skipped', function () {
    fakeQne();
    ['property' => $property] = accountingSetup();
    $empty = BusinessDate::factory()->closed()->create(['property_id' => $property->id, 'date' => '2026-10-13']);

    $sync = app(PushDailyJournal::class)($property, $empty);

    expect($sync->status)->toBe(AccountingSyncStatus::Skipped)->and(journalPosts())->toBe(0);
});

it('does nothing when the feature is off, the connection is off or the date is not closed', function () {
    fakeQne();
    ['property' => $property, 'connection' => $connection, 'date' => $date] = accountingSetup();
    $open = BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-14']);

    expect(app(PushDailyJournal::class)($property, $open))->toBeNull();

    $connection->forceFill(['enabled' => false])->save();
    expect(app(PushDailyJournal::class)($property->refresh(), $date))->toBeNull();

    $connection->forceFill(['enabled' => true])->save();
    Feature::for($property)->deactivate(AccountingIntegration::class);
    expect(app(PushDailyJournal::class)($property->refresh(), $date))->toBeNull()
        ->and(journalPosts())->toBe(0);
});

it('queues the push when the night audit completes on a property with accounting on', function () {
    Queue::fake();
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create();
    $date = BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-12']);
    AccountingConnection::factory()->for($property)->create();

    app(RunNightAudit::class)($property);

    Queue::assertPushed(PushDailyJournalJob::class, fn (PushDailyJournalJob $job): bool => $job->propertyId === $property->id && $job->businessDateId === $date->id);
});

it('does not queue a push when the property has no accounting connection', function () {
    Queue::fake();
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-12']);

    app(RunNightAudit::class)($property);

    Queue::assertNotPushed(PushDailyJournalJob::class);
});
