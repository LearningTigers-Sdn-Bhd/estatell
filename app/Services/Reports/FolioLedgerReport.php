<?php

namespace App\Services\Reports;

use App\Data\Reports\FolioLedgerRow;
use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportTotals;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\TransactionCode;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every folio line of the property posted in the range, of any kind, by posting date, optionally for one transaction
 * code (`?code=ROOM`). Amounts are signed as in the ledger: payments and reversals are negative. See
 * docs/22-reports/01-core-reports.md.
 */
class FolioLedgerReport implements HasTotals, Report
{
    /**
     * @param  list<int|string>|null  $rowKeys
     * @return list<FolioLedgerRow>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        return array_values(FolioLine::query()
            ->join('folios', 'folios.id', '=', 'folio_lines.folio_id')
            ->where('folios.property_id', $property->id)
            ->whereBetween('folio_lines.posting_date', [$filters->from, $filters->to])
            ->when($filters->code !== null, fn (Builder $query) => $query->whereIn(
                'folio_lines.transaction_code_id',
                TransactionCode::query()->where('property_id', $property->id)->where('code', $filters->code)->select('id'),
            ))
            ->when($rowKeys !== null, fn (Builder $query) => $query->whereIn('folio_lines.id', $rowKeys))
            ->select('folio_lines.*')
            ->with(['folio.reservation.booker', 'folio.payerGuest', 'folio.payerCompany', 'transactionCode', 'user'])
            ->orderBy('folio_lines.posting_date')
            ->orderBy('folio_lines.id')
            ->get()
            ->map(fn (FolioLine $line): FolioLedgerRow => FolioLedgerRow::fromModel($line))
            ->all());
    }

    /**
     * The transaction codes of the property for the code filter, by code.
     *
     * @return list<array{value: string, label: string}>
     */
    public function codeOptions(Property $property): array
    {
        return TransactionCode::query()
            ->where('property_id', $property->id)
            ->orderBy('code')
            ->get(['code', 'name'])
            ->map(fn (TransactionCode $code): array => ['value' => $code->code, 'label' => "{$code->code} · {$code->name}"])
            ->all();
    }

    /**
     * The signed sum of the amounts.
     *
     * @param  list<FolioLedgerRow>  $rows
     */
    public function totals(array $rows): ReportTotals
    {
        $amount = array_reduce($rows, fn (BigDecimal $sum, FolioLedgerRow $row): BigDecimal => $sum->plus($row->amount), BigDecimal::zero());

        return new ReportTotals([
            'date' => null,
            'folio' => null,
            'reservation' => null,
            'guest' => null,
            'code' => null,
            'description' => null,
            'kind' => null,
            'staff' => null,
            'amount' => (string) $amount->toScale(2, RoundingMode::HalfUp),
        ]);
    }
}
