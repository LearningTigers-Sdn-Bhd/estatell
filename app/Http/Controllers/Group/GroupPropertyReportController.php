<?php

namespace App\Http\Controllers\Group;

use App\Data\Group\GroupPropertyReportData;
use App\Data\Property\PropertySummaryData;
use App\Data\Reports\ReportFilters;
use App\Enums\ReportKey;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureGroupAccess;
use App\Http\Requests\Group\GroupPropertyReportRequest;
use App\Models\Property;
use App\Models\PropertyGroup;
use App\Services\Group\GroupPropertyReports;
use App\Services\Group\GroupTableExport;
use App\Services\Property\PropertyToday;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
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
            'category' => ['value' => $key->category()->value, 'label' => $key->category()->label()],
            'reports' => array_map(fn (ReportKey $option): array => [
                'value' => $option->value,
                'label' => $option->label(),
                'icon' => $option->icon(),
            ], GroupPropertyReportRequest::inCategory($key->category())),
            'oneDate' => $key->maxRangeDays() === null,
            'maxRangeDays' => $key->maxRangeDays(),
            'groups' => array_map(fn ($group): array => ['value' => $group->value, 'label' => $group->label()], $key->groups()),
            'filters' => ['from' => $filters->from, 'to' => $filters->to, 'group' => $filters->group?->value, 'property' => $this->pickedProperty($request)?->code],
            'properties' => array_values($this->groupProperties($request)->map(fn (Property $property): PropertySummaryData => PropertySummaryData::fromModel($property))->all()),
            'today' => $this->today($request)->toDateString(),
            'result' => $report,
        ]);
    }

    /**
     * Every row, each with its property, then the totals, as CSV. Display text, as on screen.
     */
    public function export(GroupPropertyReportRequest $request, PropertyGroup $propertyGroup, GroupTableExport $export): StreamedResponse
    {
        $table = $this->table($request, $propertyGroup);

        return $export->csv($table['filename'], $table['headings'], $table['rows']);
    }

    /**
     * The same rows as a landscape PDF, the totals in bold.
     */
    public function pdf(GroupPropertyReportRequest $request, PropertyGroup $propertyGroup, GroupTableExport $export): HttpResponse
    {
        $table = $this->table($request, $propertyGroup);

        return $export->pdf($table['filename'], $propertyGroup->name, $table['title'], $table['subtitle'], $table['headings'], $table['rows'], $table['totalRows']);
    }

    /**
     * @return array{filename: string, title: string, subtitle: string, headings: list<string>, rows: list<list<string>>, totalRows: list<int>}
     */
    private function table(GroupPropertyReportRequest $request, PropertyGroup $propertyGroup): array
    {
        [$key, $filters, $report] = $this->open($request, true);
        $currency = $report->currency?->code;
        $rows = [];
        $totalRows = [];

        foreach ([...$report->rows, ...$report->totals] as $row) {
            if ($row->total) {
                $totalRows[] = count($rows);
            }

            $rows[] = [$row->total ? __(':property total', ['property' => $row->property]) : $row->property, ...array_values(array_map(fn (string $cell): string => $cell === __('Total') && $row->total ? '' : $cell, $row->cells))];
        }

        $from = CarbonImmutable::parse($filters->from)->format('j M Y');
        $to = CarbonImmutable::parse($filters->to)->format('j M Y');

        return [
            'filename' => Str::slug("{$propertyGroup->name} {$key->label()} {$filters->from} {$filters->to}"),
            'title' => $key->label(),
            'subtitle' => ($from === $to ? $from : "{$from} – {$to}").($filters->group === null ? '' : ' · '.$filters->group->label()),
            'headings' => [__('Property'), ...array_map(fn ($column): string => $column->heading($currency), $report->columns)],
            'rows' => $rows,
            'totalRows' => $totalRows,
        ];
    }

    /**
     * @return array{0: ReportKey, 1: ReportFilters, 2: GroupPropertyReportData}
     */
    private function open(GroupPropertyReportRequest $request, bool $allRows): array
    {
        $picked = $this->pickedProperty($request);
        $properties = $picked === null ? $this->groupProperties($request) : new Collection([$picked]);
        $key = $request->reportKey();
        $filters = $request->filters($this->today($request));

        return [$key, $filters, $this->reports->run($request->user(), $properties, $key, $filters, $allRows, (int) ($request->validated('page') ?? 1))];
    }

    /**
     * @return Collection<int, Property>
     */
    private function groupProperties(GroupPropertyReportRequest $request): Collection
    {
        return $request->attributes->get(EnsureGroupAccess::PROPERTIES);
    }

    /**
     * The one property picked in the property filter, or null for all. A code outside the group counts as all.
     */
    private function pickedProperty(GroupPropertyReportRequest $request): ?Property
    {
        $code = $request->validated('property');

        return $code === null ? null : $this->groupProperties($request)->first(fn (Property $property): bool => $property->code === $code);
    }

    private function today(GroupPropertyReportRequest $request): CarbonImmutable
    {
        /** @var Collection<int, Property> $properties */
        $properties = $request->attributes->get(EnsureGroupAccess::PROPERTIES);

        return $this->propertyToday->date($properties->firstOrFail());
    }
}
