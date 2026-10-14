<?php

namespace App\Services\Reports;

use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportLayoutData;
use App\Data\Reports\ReportRow;
use App\Data\Reports\ReportSection;
use App\Enums\ReportKey;
use App\Enums\Tone;
use App\Models\Property;
use App\Services\Property\PrintedPdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Carbon\CarbonImmutable;

/**
 * Renders a report as a PDF on the shared print layout (`pdf/report.blade.php`): the letterhead, the title, the dates
 * and a table of the visible columns in layout order, with the total row last when the report has one. The rows come from `rows()` on the server, like the screen.
 */
class ReportPdf
{
    public function __construct(private PrintedPdf $printedPdf) {}

    /**
     * @param  list<int|string>|null  $rowKeys  Only these rows, when given.
     * @param  list<ReportRow>|null  $rows  The rows already read and sorted by the caller, for these keys. Read here when null.
     */
    public function make(Property $property, ReportKey $report, ReportFilters $filters, ReportLayoutData $layout, ?array $rowKeys = null, ?array $rows = null): DomPdf
    {
        $rows ??= $report->report()->rows($property, $filters, $rowKeys);
        $sections = $report->sections($rows, $filters);

        if ($sections !== null && $rowKeys === null) {
            $sections = array_values(array_filter($sections, fn (ReportSection $section): bool => $section->printedByDefault));
            $printedKeys = array_flip(array_merge([], ...array_map(fn (ReportSection $section): array => $section->rowKeys, $sections)));
            $rows = array_values(array_filter($rows, fn (ReportRow $row): bool => isset($printedKeys[$row->key])));
        }

        return $this->printedPdf->make($property, 'pdf.report', [
            'title' => $report->label(),
            'dates' => $this->dates($filters),
            'group' => $filters->group?->label(),
            'groupCaption' => $filters->group?->caption(),
            'note' => $report->note($property, $filters),
            'currency' => $property->loadMissing('settings')->settings?->currency->getCurrencyCode() ?? '',
            'columns' => $layout->visibleColumns(),
            'rows' => $rows,
            'sections' => $sections === null ? null : array_map(fn (ReportSection $section): array => [
                'title' => $section->title,
                'subtotal' => $section->subtotal,
                'warning' => $section->tone === Tone::Warning,
                'rows' => array_values(array_filter($rows, fn (ReportRow $row): bool => in_array($row->key, $section->rowKeys, true))),
            ], $sections),
            'totals' => $report->totals($rows),
            'breakdown' => $report->breakdown($rows, $filters),
            'selectedOnly' => $rowKeys !== null,
        ], $layout->orientation->value);
    }

    public function filename(ReportKey $report, ReportFilters $filters): string
    {
        return "{$report->value}-{$filters->from}".($filters->to === $filters->from ? '' : "-{$filters->to}").'.pdf';
    }

    private function dates(ReportFilters $filters): string
    {
        $from = CarbonImmutable::parse($filters->from)->format('j M Y');

        return $filters->to === $filters->from ? $from : $from.' - '.CarbonImmutable::parse($filters->to)->format('j M Y');
    }
}
