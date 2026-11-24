<?php

namespace App\Http\Controllers\Group;

use App\Data\Group\GroupPropertyReportData;
use App\Data\Group\GroupReportRowData;
use App\Data\Reports\ReportFilters;
use App\Enums\ReportKey;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureGroupAccess;
use App\Http\Requests\Group\GroupPropertyReportRequest;
use App\Models\Property;
use App\Models\PropertyGroup;
use App\Services\Group\GroupPropertyReports;
use App\Services\Property\PropertyToday;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Group › Property reports: one property report for every property at once, and its CSV.
 * See docs/03-staff/05-group-mode.md#property-reports.
 */
class GroupPropertyReportController extends Controller
{
    public function __construct(private GroupPropertyReports $reports, private PropertyToday $propertyToday) {}

    public function show(GroupPropertyReportRequest $request, PropertyGroup $propertyGroup): Response
    {
        [$key, $filters, $report] = $this->open($request, false);

        return Inertia::render('group/property-reports', [
            'group' => ['id' => $propertyGroup->id, 'name' => $propertyGroup->name],
            'reports' => array_map(fn (ReportKey $option): array => [
                'value' => $option->value,
                'label' => $option->label(),
                'group' => $option->category()->label(),
            ], GroupPropertyReportRequest::reports()),
            'oneDate' => $key->maxRangeDays() === null,
            'maxRangeDays' => $key->maxRangeDays(),
            'groups' => array_map(fn ($group): array => ['value' => $group->value, 'label' => $group->label()], $key->groups()),
            'filters' => ['from' => $filters->from, 'to' => $filters->to, 'group' => $filters->group?->value],
            'today' => $this->today($request)->toDateString(),
            'result' => $report,
        ]);
    }

    /**
     * Every row, each with its property, then the totals. Display text, as on screen.
     */
    public function export(GroupPropertyReportRequest $request, PropertyGroup $propertyGroup): StreamedResponse
    {
        [$key, $filters, $report] = $this->open($request, true);
        $currency = $report->currency?->code;

        return response()->streamDownload(function () use ($report, $currency): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                throw new RuntimeException('The CSV output stream could not be opened.');
            }

            fputcsv($out, [__('Property'), ...array_map(fn ($column): string => $column->heading($currency), $report->columns)]);

            foreach ([...$report->rows, ...$report->totals] as $row) {
                /** @var GroupReportRowData $row */
                fputcsv($out, [$row->total ? __(':property total', ['property' => $row->property]) : $row->property, ...array_values($row->cells)]);
            }

            fclose($out);
        }, Str::slug("{$propertyGroup->name} {$key->label()} {$filters->from} {$filters->to}").'.csv', ['Content-Type' => 'text/csv', 'Cache-Control' => 'no-store, private']);
    }

    /**
     * @return array{0: ReportKey, 1: ReportFilters, 2: GroupPropertyReportData}
     */
    private function open(GroupPropertyReportRequest $request, bool $allRows): array
    {
        /** @var Collection<int, Property> $properties */
        $properties = $request->attributes->get(EnsureGroupAccess::PROPERTIES);
        $key = $request->reportKey();
        $filters = $request->filters($this->today($request));

        return [$key, $filters, $this->reports->run($request->user(), $properties, $key, $filters, $allRows)];
    }

    private function today(GroupPropertyReportRequest $request): CarbonImmutable
    {
        /** @var Collection<int, Property> $properties */
        $properties = $request->attributes->get(EnsureGroupAccess::PROPERTIES);

        return $this->propertyToday->date($properties->firstOrFail());
    }
}
