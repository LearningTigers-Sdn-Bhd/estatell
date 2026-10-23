<?php

use App\Actions\Catalogues\SeedPaymentMethods;
use App\Actions\Catalogues\SeedTransactionCodes;
use App\Enums\AccountingProvider;
use App\Enums\SystemTransactionCode;
use App\Features\AccountingIntegration;
use App\Models\AccountingConnection;
use App\Models\ArAllocation;
use App\Models\ArInvoice;
use App\Models\ArPayment;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\TransactionCode;
use App\Services\Property\CountryProfiles;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Pennant\Feature;

/**
 * A live Malaysian property with the accounting feature on, the provider connected with company billing switched on from
 * 2026-10-01, the city ledger code mapped to a clearing account (300-100) and the bank transfer method to a bank account
 * (100-200), and one company.
 *
 * @return array{property: Property, connection: AccountingConnection, company: Company, method: PaymentMethod}
 */
function billingSetup(AccountingProvider $provider = AccountingProvider::Qne, bool $mapped = true): array
{
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create(['currency' => 'MYR']);
    app(SeedTransactionCodes::class)($property, CountryProfiles::for('MY'));
    app(SeedPaymentMethods::class)($property, CountryProfiles::for('MY'));
    Feature::for($property)->activate(AccountingIntegration::class);

    $connection = AccountingConnection::factory()->for($property)->create([
        'provider' => $provider,
        'api_key' => 'secret-key',
        'company_subdomain' => $provider === AccountingProvider::Bukku ? 'seaview' : null,
        'push_company_billing' => true,
        'company_billing_since' => '2026-10-01',
    ]);

    $method = PaymentMethod::query()->where('property_id', $property->id)->where('type', 'bank_transfer')->firstOrFail();

    if ($mapped) {
        mapCode($property, SystemTransactionCode::DirectBillPayment->value, '300-100');
        $method->transactionCode->forceFill(['gl_account_code' => '100-200'])->save();
    }

    $company = Company::factory()->for($property)->create(['name' => 'Acme Sdn Bhd', 'billing_country_code' => 'MY']);

    return ['property' => $property, 'connection' => $connection, 'company' => $company, 'method' => $method];
}

function mapCode(Property $property, string $systemKey, ?string $account): void
{
    TransactionCode::query()->where('property_id', $property->id)->where('system_key', $systemKey)->update(['gl_account_code' => $account]);
}

/**
 * A 500.00 invoice of the company issued on 2026-10-04.
 *
 * @param  array<string, mixed>  $attributes
 */
function billingInvoice(Property $property, Company $company, array $attributes = []): ArInvoice
{
    return ArInvoice::factory()->create(['property_id' => $property->id, 'company_id' => $company->id, ...$attributes]);
}

/**
 * A payment of the company that is allocated to the invoice for the given amount.
 *
 * @param  array<string, mixed>  $attributes
 */
function billingPayment(Property $property, Company $company, PaymentMethod $method, ArInvoice $invoice, string $allocated = '500.00', array $attributes = []): ArPayment
{
    $payment = ArPayment::factory()->create(['property_id' => $property->id, 'company_id' => $company->id, 'payment_method_id' => $method->id, 'amount' => $allocated, ...$attributes]);
    ArAllocation::factory()->create(['ar_payment_id' => $payment->id, 'ar_invoice_id' => $invoice->id, 'amount' => $allocated]);

    return $payment;
}

/**
 * The provider answers every call. Created documents get ids 1, 2, 3 and so on (QNE as strings with a document type).
 */
function fakeBilling(AccountingProvider $provider): void
{
    $next = 0;
    $envelope = fn (array $data) => Http::response(['code' => '0000', 'data' => $data]);

    if ($provider === AccountingProvider::Qne) {
        Http::fake([
            '*/api/Auth/Connect*' => $envelope(['token' => 'jwt']),
            '*/api/AccountCodes/Leaf/Query*' => fn (Request $request) => $envelope(['count' => 1, 'value' => [['id' => 'acc-'.md5($request['$filter'] ?? 'any')]]]),
            '*/api/*/New*' => $envelope(['currencyId' => 1]),
            '*/api/Customers/Create*' => fn () => $envelope(['id' => 700 + ++$next]),
            '*/api/SalesInvoices/Create*' => fn () => $envelope(['id' => 'inv-'.++$next, 'docType' => 'INV']),
            '*/api/ARCNs/Create*' => fn () => $envelope(['id' => 'cn-'.++$next, 'docType' => 'ARCN']),
            '*/api/ARReceipts/Create*' => fn () => $envelope(['id' => 'rc-'.++$next, 'docType' => 'OR']),
            '*/Void*' => $envelope([]),
        ]);

        return;
    }

    Http::fake([
        '*/accounts*' => Http::response(['accounts' => [['id' => 31, 'code' => '300-100'], ['id' => 32, 'code' => '100-200']]]),
        '*/contacts*' => fn () => Http::response(['contact' => ['id' => 500 + ++$next]]),
        '*/sales/*' => fn (Request $request) => Http::response($request->method() === 'PATCH' ? ['transaction' => []] : ['transaction' => ['id' => 900 + ++$next]]),
    ]);
}

/**
 * The calls the provider received whose URL contains the text, with the given method.
 *
 * @return list<Request>
 */
function billingCalls(string $needle, string $method = 'POST'): array
{
    return Http::recorded(fn (Request $request): bool => $request->method() === $method && str_contains($request->url(), $needle))
        ->map(fn (array $pair): Request => $pair[0])
        ->values()
        ->all();
}
