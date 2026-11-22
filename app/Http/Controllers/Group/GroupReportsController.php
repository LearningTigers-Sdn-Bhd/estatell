<?php

namespace App\Http\Controllers\Group;

use App\Data\Group\GroupBookingChangeRowData;
use App\Data\Group\GroupFiguresRowData;
use App\Data\Group\GroupReportsData;
use App\Data\Reports\ReportFilters;
use App\Enums\GroupReportTab;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureGroupAccess;
use App\Http\Requests\Group\GroupReportsRequest;
use App\Models\Property;
use App\Models\PropertyGroup;
use App\Services\Group\GroupReports;
use App\Services\Property\PropertyToday;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Group reports: the open tab for the dates, and its CSV. See docs/03-staff/05-group-mode.md#group-reports.
 */
class GroupReportsController extends Controller
{
    public function __construct(private GroupReports $reports, private PropertyToday $propertyToday) {}

    public function show(GroupReportsRequest $request, PropertyGroup $propertyGroup): Response
    {
        [$tab, $filters, $report] = $this->open($request);

        return Inertia::render('group/reports', [
            'group' => ['id' => $propertyGroup->id, 'name' => $propertyGroup->name],
            'tab' => $tab->value,
            'tabs' => array_map(fn (GroupReportTab $case): array => ['value' => $case->value, 'label' => $case->label()], GroupReportTab::cases()),
            'from' => $filters->from,
            'to' => $filters->to,
            'maxRangeDays' => GroupReportsRequest::MAX_DAYS,
            'report' => $report,
        ]);
    }

    /**
     * The open tab as a CSV file with the screen's rows; By property and By date end with the total row.
     */
    public function export(GroupReportsRequest $request, PropertyGroup $propertyGroup): StreamedResponse
    {
        [$tab, $filters, $report] = $this->open($request);
        [$headings, $rows] = match ($tab) {
            GroupReportTab::Bookings => [GroupBookingChangeRowData::csvHeadings(), array_map(fn (GroupBookingChangeRowData $row): array => $row->csv(), $report->bookings)],
            default => [
                GroupFiguresRowData::csvHeadings($tab === GroupReportTab::ByDate ? __('Date') : __('Property')),
                array_map(fn (GroupFiguresRowData $row): array => $row->csv(), $report->total === null ? $report->figures : [...$report->figures, $report->total]),
            ],
        };

        return response()->streamDownload(function () use ($headings, $rows): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                throw new RuntimeException('The CSV output stream could not be opened.');
            }

            fputcsv($out, $headings);

            foreach ($rows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, Str::slug("{$propertyGroup->name} {$tab->label()} {$filters->from} {$filters->to}").'.csv', ['Content-Type' => 'text/csv', 'Cache-Control' => 'no-store, private']);
    }

    /**
     * The tab, the dates and the report for them, over the properties `EnsureGroupAccess` let the user see.
     *
     * @return array{0: GroupReportTab, 1: ReportFilters, 2: GroupReportsData}
     */
    private function open(GroupReportsRequest $request): array
    {
        /** @var Collection<int, Property> $properties */
        $properties = $request->attributes->get(EnsureGroupAccess::PROPERTIES);
        $tab = $request->tab();
        $filters = $request->filters($this->propertyToday->date($properties->firstOrFail()));

        return [$tab, $filters, $this->reports->for($request->user(), $properties, $tab, $filters)];
    }
}
