<?php

use App\Actions\Accounting\PushDailyJournal;
use App\Enums\AccountingProvider;
use App\Enums\AccountingSyncStatus;
use App\Models\AccountingConnection;
use App\Models\BusinessDate;
use App\Models\Property;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Support/AccountingSetup.php';

/**
 * accountingSetup() with the connection switched to Bukku: a token, the company subdomain and the production server.
 *
 * @return array{property: Property, connection: AccountingConnection, date: BusinessDate}
 */
function bukkuSetup(bool $sandbox = false): array
{
    $setup = accountingSetup();
    $setup['connection']->forceFill([
        'provider' => AccountingProvider::Bukku,
        'api_key' => 'bukku-token',
        'company_subdomain' => 'seaview',
        'sandbox' => $sandbox,
    ])->save();

    return $setup;
}

/**
 * Bukku knows every account code the setup maps, and accepts the journal.
 */
function fakeBukku(): void
{
    Http::fake([
        '*/accounts*' => Http::response(['accounts' => [
            ['id' => 11, 'code' => '500-000'],
            ['id' => 12, 'code' => '600-000'],
            ['id' => 13, 'code' => '100-000'],
            ['id' => 14, 'code' => '300-000'],
        ]]),
        '*/journal_entries*' => Http::response(['transaction' => ['id' => 1211]]),
    ]);
}

it('pushes the journal to Bukku as a draft with the company headers', function () {
    fakeBukku();
    ['property' => $property, 'date' => $date] = bukkuSetup();

    $sync = app(PushDailyJournal::class)($property, $date);

    expect($sync->status)->toBe(AccountingSyncStatus::Pushed)->and($sync->external_id)->toBe('1211');

    Http::assertSent(function (Request $request) use ($property): bool {
        if (! str_ends_with($request->url(), '/journal_entries')) {
            return false;
        }

        $items = collect($request['journal_items'])->keyBy('account_id');

        return str_starts_with($request->url(), 'https://api.bukku.my/')
            && $request->hasHeader('Authorization', 'Bearer bukku-token')
            && $request->hasHeader('Company-Subdomain', 'seaview')
            && $request['status'] === 'draft'
            && $request['date'] === '2026-10-12'
            && $request['currency_code'] === 'MYR'
            && $request['exchange_rate'] === 1
            && $request['number2'] === "EST-{$property->code}-2026-10-12"
            && $items[11]['credit_amount'] === 100.0 && $items[11]['debit_amount'] === null
            && $items[13]['debit_amount'] === 50.0 && $items[13]['credit_amount'] === null
            && $items[14]['debit_amount'] === 56.0
            && collect($request['journal_items'])->pluck('line')->all() === [1, 2, 3, 4];
    });
});

it('uses the Bukku staging server when the test server is on', function () {
    fakeBukku();
    ['property' => $property, 'date' => $date] = bukkuSetup(sandbox: true);

    app(PushDailyJournal::class)($property, $date);

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.staging.bukku.dev/'));
    Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.bukku.my/'));
});

it('fails the date when an account is missing in Bukku and sends no journal', function () {
    Http::fake(['*/accounts*' => Http::response(['accounts' => [['id' => 11, 'code' => '500-000']]])]);
    ['property' => $property, 'date' => $date] = bukkuSetup();

    $sync = app(PushDailyJournal::class)($property, $date);

    expect($sync->status)->toBe(AccountingSyncStatus::Failed)
        ->and($sync->error)->toContain('do not exist in the accounting software')
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/journal_entries'))->count())->toBe(0);
});

it('saves a Bukku refusal on the sync row', function () {
    Http::fake(['*/accounts*' => Http::response(['message' => 'Unauthenticated.'], 401)]);
    ['property' => $property, 'date' => $date] = bukkuSetup();

    $sync = app(PushDailyJournal::class)($property, $date);

    expect($sync->status)->toBe(AccountingSyncStatus::Failed)
        ->and($sync->error)->toContain('Bukku refused')->toContain('401')->toContain('Unauthenticated.');
});

it('fails the date when the Bukku subdomain is missing, without calling Bukku', function () {
    Http::fake();
    ['property' => $property, 'connection' => $connection, 'date' => $date] = bukkuSetup();
    $connection->forceFill(['company_subdomain' => null])->save();

    $sync = app(PushDailyJournal::class)($property->refresh(), $date);

    expect($sync->status)->toBe(AccountingSyncStatus::Failed)->and($sync->error)->toContain('subdomain');
    Http::assertNothingSent();
});
