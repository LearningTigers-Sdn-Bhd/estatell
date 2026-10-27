<?php

namespace App\Http\Controllers\Reports;

use App\Data\Property\CurrencyData;
use App\Data\Reports\ArrivalRow;
use App\Data\Reports\CheckedOutRow;
use App\Data\Reports\DepartureRow;
use App\Data\Reports\InHouseRow;
use App\Enums\PageOrientation;
use App\Enums\PermissionEnum;
use App\Enums\ReportKey;
use App\Enums\TabbedReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportFiltersRequest;
use App\Models\Property;
use App\Services\Reports\GuestListCounts;
use App\Services\Reports\ReportLayoutResolver;
use App\Services\Reports\ReportPage;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;

class GuestListController extends Controller
{
    /**
     * The Guest list page: the open tab (`?tab`, Arrivals when missing or wrong) as its own report, with the count of
     * every tab for the chosen dates. Only the open tab's rows are sent.
     */
    public function __invoke(ReportFiltersRequest $request, Property $property, ReportPage $reportPage, ReportLayoutResolver $layoutResolver, GuestListCounts $counts): Response
    {
        $page = TabbedReport::GuestList;
        $requested = $request->query('tab');
        $tab = $page->tab(is_string($requested) ? $requested : null);
        $key = $page->reportKey($tab);
        $filters = $request->filters();
        $layout = $layoutResolver->resolve($property, $key);
        $allRows = $key->report()->rows($property, $filters);

        [$rows, $sort] = $reportPage->paginate(
            rows: $allRows,
            sort: $request->string('sort')->toString(),
            defaultSort: $key->defaultSort(),
            columnKeys: $layout->visibleKeys(),
            page: max(1, $request->integer('page', 1)),
            path: $request->url(),
        );

        $rowClass = match ($key) {
            ReportKey::InHouse => InHouseRow::class,
            ReportKey::Departures => DepartureRow::class,
            ReportKey::CheckedOut => CheckedOutRow::class,
            default => ArrivalRow::class,
        };

        return Inertia::render('reports/guest-list', [
            'tab' => $tab,
            'tabs' => $this->tabs($property, $filters->from, $filters->to, $counts->forDates($property, $filters->from, $filters->to, $filters->from)),
            'rows' => $rowClass::collect($rows->withQueryString(), PaginatedDataCollection::class),
            'totals' => $key === ReportKey::InHouse ? $key->totals($allRows) : null,
            'sort' => $sort,
            'layout' => $layout,
            'orientationOptions' => PageOrientation::options(),
            'canEditLayout' => $request->user()->can(PermissionEnum::SettingsManage->value),
            'from' => $filters->from,
            'to' => $filters->to,
            'maxRangeDays' => $key->maxRangeDays(),
            'currency' => CurrencyData::forProperty($property),
        ]);
    }

    /**
     * Each tab with its label, count and link. A range tab links with `from` and `to`, In-house with `date`, the first
     * date of the range (or the one date when In-house is open).
     *
     * @param  array<string, int>  $counts
     * @return list<array{value: string, label: string, count: int, href: string}>
     */
    private function tabs(Property $property, string $from, string $to, array $counts): array
    {
        $tabs = [];

        foreach (TabbedReport::GuestList->tabs() as $tab => $key) {
            $dates = $key->maxRangeDays() === null ? ['date' => $from] : ['from' => $from, 'to' => $to];

            $tabs[] = [
                'value' => $tab,
                'label' => $key->label(),
                'count' => $counts[$tab],
                'href' => route('reports.guest-list', ['property' => $property->code, 'tab' => $tab, ...$dates], absolute: false),
            ];
        }

        return $tabs;
    }
}
