<?php

namespace App\Services\CityLedger;

use App\Enums\ArInvoiceStatus;
use App\Enums\FolioStatus;
use App\Enums\ReservationStatus;
use App\Models\ArInvoice;
use App\Models\ArPayment;
use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * What a company owes or may owe: its open AR (invoices and credit notes that are not Void, minus the payments it
 * made that are not voided, never below 0) plus the balances of its Open folios on reservations that are not
 * Cancelled or Completed. A folio balance below 0 counts as 0. Amounts are decimal strings with two places. The
 * limit is `companies.credit_limit`: empty means no limit. It writes nothing.
 * See docs/15-city-ledger/04-credit-control-and-reports.md#credit-exposure.
 */
class CreditExposure
{
    /**
     * @return array{openAr: string, inHouse: string, exposure: string, limit: string|null, headroom: string|null}
     */
    public function forCompany(Company $company): array
    {
        $openAr = $this->openAr($company);
        $inHouse = $this->inHouse($company);
        $exposure = bcadd($openAr, $inHouse, 2);
        $limit = $company->credit_limit === null ? null : (string) $company->credit_limit;

        return [
            'openAr' => $openAr,
            'inHouse' => $inHouse,
            'exposure' => $exposure,
            'limit' => $limit,
            'headroom' => $limit === null ? null : bcsub($limit, $exposure, 2),
        ];
    }

    /**
     * Invoices plus credit notes (negative) that are not Void, minus payments that are not voided, never below 0.
     * Allocations move money between an invoice and a payment, so they cancel out of the total.
     */
    public function openAr(Company $company): string
    {
        $documents = (string) ArInvoice::query()
            ->where('company_id', $company->id)
            ->where('status', '<>', ArInvoiceStatus::Void)
            ->selectRaw('COALESCE(SUM(amount), 0)::numeric(14, 2) AS total')
            ->value('total');

        $payments = (string) ArPayment::query()
            ->where('company_id', $company->id)
            ->whereNull('voided_at')
            ->selectRaw('COALESCE(SUM(amount), 0)::numeric(14, 2) AS total')
            ->value('total');

        $balance = bcsub($documents, $payments, 2);

        return bccomp($balance, '0', 2) > 0 ? $balance : '0.00';
    }

    /**
     * The balances above 0 of the company's Open folios on live reservations.
     */
    public function inHouse(Company $company): string
    {
        $balances = DB::table('folio_lines')
            ->join('folios', 'folios.id', '=', 'folio_lines.folio_id')
            ->join('reservations', 'reservations.id', '=', 'folios.reservation_id')
            ->where('folios.property_id', $company->property_id)
            ->where('folios.payer_company_id', $company->id)
            ->where('folios.status', FolioStatus::Open->value)
            ->whereNotIn('reservations.status', [ReservationStatus::Cancelled->value, ReservationStatus::Completed->value])
            ->groupBy('folio_lines.folio_id')
            ->selectRaw('SUM(folio_lines.amount)::numeric(14, 2) AS balance')
            ->pluck('balance');

        return $balances->reduce(
            fn (string $sum, mixed $balance): string => bccomp((string) $balance, '0', 2) > 0 ? bcadd($sum, (string) $balance, 2) : $sum,
            '0.00',
        );
    }
}
