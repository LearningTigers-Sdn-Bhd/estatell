<?php

namespace App\Http\Controllers\Reports;

use App\Data\Property\CurrencyData;
use App\Data\Reports\BookingChangeRow;
use App\Data\Reports\ReportFilters;
use App\Data\Reports\RoomsOnBooksRow;
use App\Enums\PageOrientation;
use App\Enums\PermissionEnum;
use App\Enums\ReportKey;
use App\Enums\TabbedReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportFiltersRequest;
use App\Models\Property;
use App\Services\Reports\ReportLayoutResolver;
use App\Services\Reports\ReportPage;
use App\Services\Reports\RoomsOnBooksOverview;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;

class RoomsOnBooksController extends Controller
{
    /**
     * The Rooms on books page: the open tab (`?tab`, Overview when missing or wrong). Overview sends its tables only,
     * On the books its day grid, and Changes every change unpaged in its sections. See
     * docs/22-reports/05-rooms-on-books.md.
     */
    public function __invoke(ReportFiltersRequest $request, Property $property, ReportPage $reportPage, ReportLayoutResolver $layoutResolver, RoomsOnBooksOverview $overview): Response
    {
        $page = TabbedReport::RoomsOnBooks;
        $requested = $request->query('tab');
        $tab = $page->tab(is_string($requested) ? $requested : null);
        $key = $page->reportKey($tab);
        $filters = $request->filters();

        $props = [
            'tab' => $tab,
            'tabs' => $this->tabs($property, $filters, $key),
            'from' => $filters->from,
            'to' => $filters->to,
            'maxRangeDays' => $key->maxRangeDays(),
            'presets' => $key->datePresets(),
            'currency' => CurrencyData::forProperty($property),
        ];

        if ($tab === 'overview') {
            return Inertia::render('reports/rooms-on-books', [
                ...$props,
                'overview' => $overview->forDates($property, $filters, CarbonImmutable::parse($request->businessDate())),
            ]);
        }

        $layout = $layoutResolver->resolve($property, $key);
        $allRows = $key->report()->rows($property, $filters);

        if ($key === ReportKey::BookingChanges) {
            $rows = new LengthAwarePaginator($allRows, count($allRows), max(1, count($allRows)), 1, ['path' => $request->url()]);
            $sort = $key->defaultSort();
        } else {
            [$rows, $sort] = $reportPage->paginate(
                rows: $allRows,
                sort: $request->string('sort')->toString(),
                defaultSort: $key->defaultSort(),
                columnKeys: $layout->visibleKeys(),
                page: max(1, $request->integer('page', 1)),
                path: $request->url(),
            );
        }

        return Inertia::render('reports/rooms-on-books', [
            ...$props,
            'rows' => $key === ReportKey::BookingChanges
                ? BookingChangeRow::collect($rows->withQueryString(), PaginatedDataCollection::class)
                : RoomsOnBooksRow::collect($rows->withQueryString(), PaginatedDataCollection::class),
            'totals' => $key->totals($allRows),
            'sections' => $key->sections($allRows, $filters),
            'sort' => $sort,
            'layout' => $layout,
            'orientationOptions' => PageOrientation::options(),
            'canEditLayout' => $request->user()->can(PermissionEnum::SettingsManage->value),
        ]);
    }

    /**
     * Each tab with its label and link. A tab keeps the dates only when it reads the same report as the open tab:
     * Overview and Changes share the dates of the changes, and On the books looks forward from its own default days.
     *
     * @return list<array{value: string, label: string, href: string}>
     */
    private function tabs(Property $property, ReportFilters $filters, ReportKey $current): array
    {
        $labels = ['overview' => __('Overview'), 'on-the-books' => __('On the books'), 'changes' => __('Changes')];
        $tabs = [];

        foreach (TabbedReport::RoomsOnBooks->tabs() as $tab => $key) {
            $dates = $key === $current ? ['from' => $filters->from, 'to' => $filters->to] : [];

            $tabs[] = [
                'value' => $tab,
                'label' => $labels[$tab],
                'href' => route('reports.rooms-on-books', ['property' => $property->code, 'tab' => $tab, ...$dates], absolute: false),
            ];
        }

        return $tabs;
    }
}
