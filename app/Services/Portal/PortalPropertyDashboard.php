<?php

namespace App\Services\Portal;

use App\Data\CityLedger\ArInvoiceRowData;
use App\Data\Portal\PortalDashboardStatsData;
use App\Enums\ArInvoiceStatus;
use App\Enums\ArInvoiceType;
use App\Enums\UnitStatus;
use App\Models\ArInvoice;
use App\Models\Company;
use App\Models\Property;
use App\Services\CityLedger\ArInvoiceDirectory;
use App\Services\CityLedger\CreditExposure;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Reads the figures and the open invoices of the property dashboard for one company at one property. Every query is
 * scoped by the property and the company. The open invoices and their balances come from one query, the credit from
 * the staff `CreditExposure` (so it matches the over-limit refusal at booking), and the in-house count from one more.
 * It writes nothing. See docs/23-company-portal/01-company-portal.md#pages.
 */
class PortalPropertyDashboard
{
    /**
     * Arrivals and open invoices the dashboard lists. The stats count them all.
     */
    public const int LIST_LIMIT = 5;

    public function __construct(private CreditExposure $creditExposure, private ArInvoiceDirectory $directory) {}

    /**
     * @param  int  $arrivals  The company's arrivals in the next 7 days, from `PortalArrivals`.
     * @param  list<ArInvoiceRowData>  $openInvoices  From `openInvoices()`.
     */
    public function stats(Company $company, array $openInvoices, int $arrivals): PortalDashboardStatsData
    {
        $credit = $this->creditExposure->forCompany($company);
        $overdue = array_values(array_filter($openInvoices, fn (ArInvoiceRowData $invoice): bool => $invoice->overdue));
        $headroom = $credit['headroom'];

        return new PortalDashboardStatsData(
            openBalance: $credit['openAr'],
            openInvoices: count($openInvoices),
            overdue: (string) array_reduce($overdue, fn (BigDecimal $sum, ArInvoiceRowData $invoice): BigDecimal => $sum->plus((string) $invoice->balance), BigDecimal::zero())->toScale(2),
            overdueInvoices: count($overdue),
            arrivals: $arrivals,
            inHouse: $this->inHouse($company),
            creditLimit: $credit['limit'],
            creditAvailable: $headroom === null ? null : (BigDecimal::of($headroom)->isNegative() ? '0.00' : $headroom),
        );
    }

    /**
     * The company's Open invoices that still have a balance, soonest due first (no due date last).
     *
     * @return list<ArInvoiceRowData>
     */
    public function openInvoices(Property $property, Company $company): array
    {
        $today = $this->directory->today($property);
        $figures = $this->directory->balanceFigureSubqueries();

        return ArInvoice::query()
            ->where('ar_invoices.property_id', $property->id)
            ->where('ar_invoices.company_id', $company->id)
            ->where('ar_invoices.type', ArInvoiceType::Invoice->value)
            ->where('ar_invoices.status', ArInvoiceStatus::Open->value)
            ->addSelect('ar_invoices.*')
            ->selectSub($figures['credited'], 'credited')
            ->selectSub($figures['allocated'], 'allocated')
            ->with('company:id,name')
            ->orderByRaw('ar_invoices.due_on ASC NULLS LAST')
            ->orderBy('ar_invoices.id')
            ->get()
            ->map(fn (ArInvoice $invoice): ArInvoiceRowData => $this->directory->row($invoice, $today))
            ->filter(fn (ArInvoiceRowData $row): bool => $row->balance !== null && BigDecimal::of($row->balance)->isPositive())
            ->values()
            ->all();
    }

    /**
     * Reservations of the company with a unit checked in now.
     */
    private function inHouse(Company $company): int
    {
        return DB::table('reservations')
            ->join('reservation_units', 'reservation_units.reservation_id', '=', 'reservations.id')
            ->where('reservations.property_id', $company->property_id)
            ->where('reservations.company_id', $company->id)
            ->where('reservation_units.status', UnitStatus::CheckedIn->value)
            ->distinct()
            ->count('reservations.id');
    }
}
