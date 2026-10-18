<?php

namespace App\Services\CityLedger;

use App\Data\CityLedger\ArInvoiceRowData;
use App\Enums\ArInvoiceStatus;
use App\Enums\ArInvoiceType;
use App\Models\ArAllocation;
use App\Models\ArInvoice;
use App\Models\Property;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Property\PropertyToday;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The invoices list: every AR invoice and credit note of a property with search, type and status filters and
 * sorting in the URL. The balance of each invoice comes from one sub-query, so the list runs a constant number of
 * queries. See docs/15-city-ledger/02-ar-invoices.md#pages.
 */
class ArInvoiceDirectory
{
    public const int PER_PAGE = 25;

    public function __construct(private CurrentBusinessDate $currentBusinessDate, private PropertyToday $propertyToday, private ArInvoiceBalance $arInvoiceBalance) {}

    /**
     * The business date as `Y-m-d`, which decides what is overdue.
     */
    public function today(Property $property): string
    {
        return ($this->currentBusinessDate->date($property) ?? $this->propertyToday->date($property))->toDateString();
    }

    /**
     * @return LengthAwarePaginator<int, ArInvoiceRowData>
     */
    public function paginate(Property $property, Request $request): LengthAwarePaginator
    {
        $today = $this->today($property);

        $figures = $this->balanceFigureSubqueries();
        $query = QueryBuilder::for(ArInvoice::query()->where('ar_invoices.property_id', $property->id), $request)
            ->allowedFilters(
                AllowedFilter::callback('search', fn (Builder $query, string $search) => $query->where(fn (Builder $query) => $query
                    ->where('ar_invoices.number', 'ilike', '%'.$search.'%')
                    ->orWhereHas('company', fn (Builder $company) => $company->where('name', 'ilike', '%'.$search.'%')))),
                AllowedFilter::callback('type', fn (Builder $query, string $type) => ArInvoiceType::tryFrom($type) === null ? $query : $query->where('ar_invoices.type', $type)),
                AllowedFilter::callback('status', fn (Builder $query, string $status) => ArInvoiceStatus::tryFrom($status) === null ? $query : $query->where('ar_invoices.status', $status)),
            )
            ->allowedSorts('issued_on', 'due_on', 'amount', 'number')
            ->defaultSort('-issued_on', '-id');

        return $query
            ->addSelect('ar_invoices.*')
            ->selectSub($figures['credited'], 'credited')
            ->selectSub($figures['allocated'], 'allocated')
            ->with('company:id,name')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (ArInvoice $invoice): ArInvoiceRowData => $this->row($invoice, $today));
    }

    /**
     * The two sub-selects an invoice's balance is built from: its non-void credit notes (`credited`) and its active
     * allocations (`allocated`). Add them with `selectSub()` beside `ar_invoices.*`, so the list and the portal read
     * every balance in the same query and never run one query per invoice.
     *
     * @return array{credited: Builder, allocated: Builder}
     */
    public function balanceFigureSubqueries(): array
    {
        return [
            'credited' => ArInvoice::query()
                ->from('ar_invoices as credits')
                ->selectRaw('COALESCE(SUM(credits.amount), 0)')
                ->whereColumn('credits.credit_for_invoice_id', 'ar_invoices.id')
                ->where('credits.status', '<>', ArInvoiceStatus::Void->value),
            'allocated' => ArAllocation::query()
                ->selectRaw('COALESCE(SUM(ar_allocations.amount), 0)')
                ->whereColumn('ar_allocations.ar_invoice_id', 'ar_invoices.id')
                ->whereNull('ar_allocations.reversed_at'),
        ];
    }

    /**
     * One list row from an invoice that has the figures of `withBalanceFigures()` and its `company` loaded.
     */
    public function row(ArInvoice $invoice, string $today): ArInvoiceRowData
    {
        return ArInvoiceRowData::fromModel(
            $invoice,
            $this->arInvoiceBalance->fromFigures((string) $invoice->amount, (string) $invoice->getAttribute('credited'), (string) $invoice->getAttribute('allocated')),
            $today,
        );
    }
}
