<?php

namespace App\Http\Controllers\Group;

use App\Data\Group\GroupBookingChangeRowData;
use App\Data\Group\GroupFiguresRowData;
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
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Group reports: the open tab for the dates, and its CSV. See docs/03-staff/05-group-mode.md#group-reports.
 */
class GroupReportsController extends Controller
{
    public function __construct(private GroupReports $reports, private PropertyToday $propertyToday) {}

    public function show(GroupReportsRequest $request, PropertyGroup $propertyGroup): Response
    {
        $properties = $this->properties($request);
        $tab = $request->tab();
        $filters = $request->filters($this->propertyToday->date($properties->firstOrFail()));

        return Inertia::render('group/reports', [
            'group' => ['id' => $propertyGroup->id, 'name' => $propertyGroup->name],
            'tab' => $tab->value,
            'tabs' => array_map(fn (GroupReportTab $case): array => ['value' => $case->value, 'label' => $case->label()], GroupReportTab::cases()),
            'from' => $filters->from,
            'to' => $filters->to,
            'maxRangeDays' => GroupReportsRequest::MAX_DAYS,
            'report' => $this->reports->for($request->user(), $properties, $tab, $filters),
        ]);
    }

    /**
     * The open tab as a CSV file, the same rows as the screen; By property and By date end with the total row.
     */
    public function export(GroupReportsRequest $request, PropertyGroup $propertyGroup): StreamedResponse
    {
        $properties = $this->properties($request);
        $tab = $request->tab();
        $filters = $request->filters($this->propertyToday->date($properties->firstOrFail()));
        $report = $this->reports->for($request->user(), $properties, $tab, $filters);
        $rows = $tab === GroupReportTab::Bookings
            ? array_map(fn (GroupBookingChangeRowData $row): array => $row->csv(), $report->bookings)
            : array_map(fn (GroupFiguresRowData $row): array => $row->csv(), $report->total === null ? $report->figures : [...$report->figures, $report->total]);
        $name = Str::slug("{$propertyGroup->name} {$tab->label()} {$filters->from} {$filters->to}").'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            if ($rows !== []) {
                fputcsv($out, array_keys($rows[0]));
            }

            foreach ($rows as $row) {
                fputcsv($out, array_values($row));
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv', 'Cache-Control' => 'no-store, private']);
    }

    /**
     * @return Collection<int, Property>
     */
    private function properties(GroupReportsRequest $request): Collection
    {
        /** @var Collection<int, Property> */
        return $request->attributes->get(EnsureGroupAccess::PROPERTIES);
    }
}
