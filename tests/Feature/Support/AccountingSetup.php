<?php

use App\Enums\SystemTransactionCode;
use App\Enums\TransactionKind;
use App\Features\AccountingIntegration;
use App\Models\AccountingConnection;
use App\Models\BusinessDate;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\TransactionCode;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Pennant\Feature;

/**
 * A live property with QNE connected, the feature on, a closed business date 2026-10-12 and one folio that holds
 * room revenue 100.00, SST 6.00 and a cash payment of 50.00 on that date. Room, SST and cash are mapped.
 *
 * @return array{property: Property, connection: AccountingConnection, date: BusinessDate, folio: Folio}
 */
function accountingSetup(bool $mapped = true): array
{
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create();
    Feature::for($property)->activate(AccountingIntegration::class);
    $connection = AccountingConnection::factory()->for($property)->create(['guest_ledger_account_code' => '300-000']);
    $date = BusinessDate::factory()->closed()->create(['property_id' => $property->id, 'date' => '2026-10-12']);
    $folio = Folio::factory()->create(['property_id' => $property->id]);

    $lines = [
        [SystemTransactionCode::RoomRevenue, TransactionKind::Charge, '100.00', '500-000'],
        [SystemTransactionCode::SstTax, TransactionKind::Tax, '6.00', '600-000'],
        [SystemTransactionCode::CashPayment, TransactionKind::Payment, '-50.00', '100-000'],
    ];

    foreach ($lines as [$system, $kind, $amount, $account]) {
        $code = TransactionCode::factory()->for($property)->system($system)->create(['gl_account_code' => $mapped ? $account : null]);
        FolioLine::factory()->create([
            'folio_id' => $folio->id,
            'transaction_code_id' => $code->id,
            'kind' => $kind,
            'amount' => $amount,
            'unit_amount' => $amount,
            'posting_date' => '2026-10-12',
        ]);
    }

    return ['property' => $property, 'connection' => $connection, 'date' => $date, 'folio' => $folio];
}

/**
 * QNE answers every call: a token, an id for every account code asked, a journal template and the created journal.
 */
function fakeQne(bool $refuseFirstSignIn = false): void
{
    $signIn = Http::sequence();

    if ($refuseFirstSignIn) {
        $signIn->push(['code' => '9999', 'message' => 'Invalid key']);
    }

    $signIn->whenEmpty(Http::response(['code' => '0000', 'data' => ['token' => 'jwt']]));

    Http::fake([
        '*/api/Auth/Connect*' => $signIn,
        '*/api/AccountCodes/Leaf/Query*' => fn (Request $request) => Http::response(['code' => '0000', 'data' => ['count' => 1, 'value' => [['id' => 'acc-'.md5($request['$filter'] ?? 'any')]]]]),
        '*/api/Journals/New*' => Http::response(['code' => '0000', 'data' => ['currencyId' => 1, 'currencyRate' => 1]]),
        '*/api/Journals/Create*' => Http::response(['code' => '0000', 'data' => ['id' => 'journal-1']]),
    ]);
}

function journalPosts(): int
{
    return Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'api/Journals/Create'))->count();
}
