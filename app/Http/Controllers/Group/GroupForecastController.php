<?php

namespace App\Http\Controllers\Group;

use App\Data\Group\GroupForecastData;
use App\Data\Group\GroupForecastRowData;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureGroupAccess;
use App\Http\Requests\Group\GroupForecastRequest;
use App\Models\Property;
use App\Models\PropertyGroup;
use App\Services\Group\GroupForecast;
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
 * Group › Forecast: the months ahead at every property, beside last year, and its CSV.
 * See docs/03-staff/05-group-mode.md#forecast.
 */
class GroupForecastController extends Controller
{
    public function __construct(private GroupForecast $forecast, private PropertyToday $propertyToday) {}

    public function show(GroupForecastRequest $request, PropertyGroup $propertyGroup): Response
    {
        [$firstMonth, $months, $report] = $this->open($request);

        return Inertia::render('group/forecast', [
            'group' => ['id' => $propertyGroup->id, 'name' => $propertyGroup->name],
            'from' => $firstMonth->format('Y-m'),
            'months' => $months,
            'maxMonths' => GroupForecast::MAX_MONTHS,
            'thisMonth' => $this->today($request)->format('Y-m'),
            'forecast' => $report,
        ]);
    }

    /**
     * Every row on screen as CSV: each property month by month, then all properties.
     */
    public function export(GroupForecastRequest $request, PropertyGroup $propertyGroup, GroupTableExport $export): StreamedResponse
    {
        $table = $this->table($request, $propertyGroup);

        return $export->csv($table['filename'], $table['headings'], $table['rows']);
    }

    /**
     * The same rows as a landscape PDF, the all-properties rows in bold.
     */
    public function pdf(GroupForecastRequest $request, PropertyGroup $propertyGroup, GroupTableExport $export): HttpResponse
    {
        $table = $this->table($request, $propertyGroup);

        return $export->pdf($table['filename'], $propertyGroup->name, __('Forecast'), $table['subtitle'], $table['headings'], $table['rows'], $table['totalRows']);
    }

    /**
     * @return array{filename: string, subtitle: string, headings: list<string>, rows: list<list<string|int|null>>, totalRows: list<int>}
     */
    private function table(GroupForecastRequest $request, PropertyGroup $propertyGroup): array
    {
        [$firstMonth, $months, $report] = $this->open($request);
        $names = collect($report->properties)->mapWithKeys(fn ($property): array => [$property->code => $property->name])->all();
        $rows = [];
        $totalRows = [];

        foreach ($report->rows as $row) {
            if ($row->code === null) {
                $totalRows[] = count($rows);
            }

            $rows[] = $row->csv($row->code === null ? __('All properties') : $names[$row->code]);
        }

        return [
            'filename' => Str::slug("{$propertyGroup->name} forecast {$firstMonth->format('Y-m')} {$months} months"),
            'subtitle' => $firstMonth->format('M Y').' – '.$firstMonth->addMonthsNoOverflow($months - 1)->format('M Y'),
            'headings' => GroupForecastRowData::csvHeadings(),
            'rows' => $rows,
            'totalRows' => $totalRows,
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: int, 2: GroupForecastData}
     */
    private function open(GroupForecastRequest $request): array
    {
        /** @var Collection<int, Property> $properties */
        $properties = $request->attributes->get(EnsureGroupAccess::PROPERTIES);
        $firstMonth = $request->firstMonth($this->today($request));
        $months = $request->months();

        return [$firstMonth, $months, $this->forecast->for($request->user(), $properties, $firstMonth, $months)];
    }

    private function today(GroupForecastRequest $request): CarbonImmutable
    {
        /** @var Collection<int, Property> $properties */
        $properties = $request->attributes->get(EnsureGroupAccess::PROPERTIES);

        return $this->propertyToday->date($properties->firstOrFail());
    }
}
