<?php

namespace App\Services\CityLedger;

use App\Enums\ArInvoiceStatus;
use App\Enums\FolioStatus;
use App\Models\ArInvoice;
use App\Models\ArPayment;
use App\Models\Company;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What a company owes or may owe: its open AR (invoices and credit notes that are not Void, minus the payments it
 * made that are not voided, never below 0) plus the balances of its Open folios (Master or ThirdParty, by
 * `payer_company_id`) whatever the reservation status, so a departed folio nobody has settled yet still counts. A
 * folio balance below 0 counts as 0. The `inHouse` key keeps its historical name: it is the open folio balance. Amounts are decimal strings with two places. The
 * limit is `companies.credit_limit`: empty means no limit. It writes nothing, and `forCompanies` reads any number of
 * companies in three queries. See docs/15-city-ledger/04-credit-control-and-reports.md#credit-exposure.
 */
class CreditExposure
{
    /**
     * @return array{openAr: string, inHouse: string, exposure: string, limit: string|null, headroom: string|null}
     */
    public function forCompany(Company $company): array
    {
        return $this->forCompanies(collect([$company]))[$company->id];
    }

    /**
     * The figures of each company, by company id. The companies belong to one property.
     *
     * @param  Collection<int, Company>  $companies
     * @return array<int, array{openAr: string, inHouse: string, exposure: string, limit: string|null, headroom: string|null}>
     */
    public function forCompanies(Collection $companies): array
    {
        $ids = array_values(array_map(intval(...), $companies->pluck('id')->all()));
        $openAr = $this->openAr($ids);
        $inHouse = $this->inHouse($ids, (int) $companies->first()?->property_id);
        $figures = [];

        foreach ($companies as $company) {
            $exposure = BigDecimal::of($openAr[$company->id])->plus($inHouse[$company->id]);
            $limit = $company->credit_limit === null ? null : (string) $company->credit_limit;

            $figures[$company->id] = [
                'openAr' => $openAr[$company->id],
                'inHouse' => $inHouse[$company->id],
                'exposure' => (string) $exposure->toScale(2),
                'limit' => $limit,
                'headroom' => $limit === null ? null : (string) BigDecimal::of($limit)->minus($exposure)->toScale(2),
            ];
        }

        return $figures;
    }

    /**
     * Whether the exposure is above the limit. A company with no limit never is.
     *
     * @param  array{exposure: string, limit: string|null}  $figures
     */
    public function isOverLimit(array $figures): bool
    {
        return $figures['limit'] !== null && BigDecimal::of($figures['exposure'])->isGreaterThan($figures['limit']);
    }

    /**
     * Invoices plus credit notes (negative) that are not Void, minus payments that are not voided, never below 0.
     * Allocations move money between an invoice and a payment, so they cancel out of the total.
     *
     * @param  list<int>  $companyIds
     * @return array<int, string>
     */
    public function openAr(array $companyIds): array
    {
        $documents = ArInvoice::query()
            ->whereIn('company_id', $companyIds)
            ->where('status', '<>', ArInvoiceStatus::Void)
            ->groupBy('company_id')
            ->selectRaw('company_id, SUM(amount)::numeric(14, 2) AS total')
            ->pluck('total', 'company_id');

        $payments = ArPayment::query()
            ->whereIn('company_id', $companyIds)
            ->whereNull('voided_at')
            ->groupBy('company_id')
            ->selectRaw('company_id, SUM(amount)::numeric(14, 2) AS total')
            ->pluck('total', 'company_id');

        $balances = [];

        foreach ($companyIds as $id) {
            $balance = BigDecimal::of((string) ($documents[$id] ?? '0'))->minus((string) ($payments[$id] ?? '0'));
            $balances[$id] = $balance->isPositive() ? (string) $balance->toScale(2) : '0.00';
        }

        return $balances;
    }

    /**
     * The balances above 0 of the companies' Open folios, whatever the status of their reservation. The key `inHouse`
     * is a historical name for this open folio balance. A settled folio is Closed, so it moves to open AR and never counts twice.
     *
     * @param  list<int>  $companyIds
     * @return array<int, string>
     */
    public function inHouse(array $companyIds, int $propertyId): array
    {
        $folios = DB::table('folio_lines')
            ->join('folios', 'folios.id', '=', 'folio_lines.folio_id')
            ->where('folios.property_id', $propertyId)
            ->whereIn('folios.payer_company_id', $companyIds)
            ->where('folios.status', FolioStatus::Open->value)
            ->groupBy('folio_lines.folio_id', 'folios.payer_company_id')
            ->selectRaw('folios.payer_company_id AS company_id, SUM(folio_lines.amount)::numeric(14, 2) AS balance')
            ->get();

        $totals = array_fill_keys($companyIds, '0.00');

        foreach ($folios as $folio) {
            if (BigDecimal::of((string) $folio->balance)->isPositive()) {
                $totals[(int) $folio->company_id] = (string) BigDecimal::of($totals[(int) $folio->company_id])->plus((string) $folio->balance)->toScale(2);
            }
        }

        return $totals;
    }
}
