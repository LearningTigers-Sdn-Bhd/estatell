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
use App\Services\Property\PropertyToday;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
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
     * Every row on screen: each property month by month, then all properties.
     */
    public function export(GroupForecastRequest $request, PropertyGroup $propertyGroup): StreamedResponse
    {
        [$firstMonth, $months, $report] = $this->open($request);
        $names = collect($report->properties)->mapWithKeys(fn ($property): array => [$property->code => $property->name])->all();

        return response()->streamDownload(function () use ($report, $names): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                throw new RuntimeException('The CSV output stream could not be opened.');
            }

            fputcsv($out, GroupForecastRowData::csvHeadings());

            foreach ($report->rows as $row) {
                fputcsv($out, $row->csv($row->code === null ? __('All properties') : $names[$row->code]));
            }

            fclose($out);
        }, Str::slug("{$propertyGroup->name} forecast {$firstMonth->format('Y-m')} {$months} months").'.csv', ['Content-Type' => 'text/csv', 'Cache-Control' => 'no-store, private']);
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
