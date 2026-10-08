<?php

namespace App\Services\Reports;

use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportTotals;
use App\Data\Reports\TaxRow;
use App\Enums\ReportGroup;
use App\Enums\TransactionKind;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\Tax;
use App\Models\TransactionCode;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Tax collected, read live from the folio lines of kind Tax by posting date: one row for each date and tax, or for each
 * month and tax with `group=month`. A tax is found by the transaction code of its lines (one code for each tax). Tax
 * lines do not store their base, so there is no base column. Reversals reduce the date they were posted on.
 * See docs/22-reports/01-core-reports.md.
 */
class TaxReport implements HasTotals, Report
{
    /**
     * @param  list<int|string>|null  $rowKeys
     * @return list<TaxRow>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        $group = $filters->group ?? ReportGroup::Day;
        $lines = FolioLine::query()
            ->join('folios', 'folios.id', '=', 'folio_lines.folio_id')
            ->where('folios.property_id', $property->id)
            ->where('folio_lines.kind', TransactionKind::Tax->value)
            ->whereBetween('folio_lines.posting_date', [$filters->from, $filters->to])
            ->groupBy('folio_lines.posting_date', 'folio_lines.transaction_code_id')
            ->orderBy('folio_lines.posting_date')
            ->orderBy('folio_lines.transaction_code_id')
            ->selectRaw('folio_lines.posting_date as date, folio_lines.transaction_code_id, SUM(folio_lines.amount) as amount, COUNT(*) as line_count')
            ->get();

        $codeIds = $lines->pluck('transaction_code_id')->unique()->all();
        $taxes = Tax::query()->where('property_id', $property->id)->whereIn('transaction_code_id', $codeIds)->get()->keyBy('transaction_code_id');
        $codes = TransactionCode::query()->where('property_id', $property->id)->whereIn('id', $codeIds)->get(['id', 'name'])->keyBy('id');

        /** @var array<string, array{period: string, codeId: int, lines: int, amount: BigDecimal}> $buckets */
        $buckets = [];

        foreach ($lines as $line) {
            $period = CarbonImmutable::parse((string) $line->getAttribute('date'))->format($group === ReportGroup::Month ? 'Y-m' : 'Y-m-d');
            $codeId = (int) $line->getAttribute('transaction_code_id');
            $key = "{$period}:{$codeId}";
            $bucket = $buckets[$key] ?? ['period' => $period, 'codeId' => $codeId, 'lines' => 0, 'amount' => BigDecimal::zero()];
            $buckets[$key] = [
                ...$bucket,
                'lines' => $bucket['lines'] + (int) $line->getAttribute('line_count'),
                'amount' => $bucket['amount']->plus((string) $line->getAttribute('amount')),
            ];
        }

        if ($rowKeys !== null) {
            $buckets = array_intersect_key($buckets, array_flip(array_map('strval', $rowKeys)));
        }

        $rows = [];

        foreach ($buckets as $key => $bucket) {
            $tax = $taxes->get($bucket['codeId']);
            $rows[] = new TaxRow(
                key: $key,
                group: $group,
                period: $bucket['period'],
                tax: $tax?->name ?? $codes->get($bucket['codeId'])?->name ?? '',
                registration: $tax?->registration_number,
                lines: $bucket['lines'],
                amount: (string) $bucket['amount']->toScale(2, RoundingMode::HalfUp),
            );
        }

        return $rows;
    }

    /**
     * The number of lines and the amount of the rows.
     *
     * @param  list<TaxRow>  $rows
     */
    public function totals(array $rows): ReportTotals
    {
        $amount = array_reduce($rows, fn (BigDecimal $sum, TaxRow $row): BigDecimal => $sum->plus($row->amount), BigDecimal::zero());

        return new ReportTotals([
            'period' => null,
            'tax' => null,
            'registration' => null,
            'lines' => array_sum(array_map(fn (TaxRow $row): int => $row->lines, $rows)),
            'amount' => (string) $amount->toScale(2, RoundingMode::HalfUp),
        ]);
    }
}
