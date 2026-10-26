<?php

namespace App\Services\Reports;

use App\Data\Property\CurrencyData;
use App\Data\Reports\PaymentRow;
use App\Data\Reports\ReportFilters;
use App\Enums\PageOrientation;
use App\Enums\ReportGroup;
use App\Enums\ReportKey;
use App\Models\Property;
use Spatie\LaravelData\PaginatedDataCollection;

/**
 * The props of the Payments report page, shared by the Payments page and Daily report › Cashier activity so that both
 * show the same rows, totals, layout and filters. It reads only.
 */
class PaymentsReportPage
{
    public function __construct(
        private PaymentsReport $report,
        private ReportPage $reportPage,
        private ReportLayoutResolver $layoutResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function props(Property $property, ReportFilters $filters, string $sort, int $page, string $path, bool $canEditLayout): array
    {
        $key = ReportKey::Payments;
        $layout = $this->layoutResolver->resolve($property, $key);
        $allRows = $this->report->rows($property, $filters);

        [$rows, $sort] = $this->reportPage->paginate(
            rows: $allRows,
            sort: $sort,
            defaultSort: $key->defaultSort($filters->group),
            columnKeys: $layout->visibleKeys(),
            page: $page,
            path: $path,
        );

        return [
            'rows' => PaymentRow::collect($rows->withQueryString(), PaginatedDataCollection::class),
            'totals' => $key->totals($allRows),
            'breakdown' => $key->breakdown($property, $allRows, $filters),
            'sort' => $sort,
            'layout' => $layout,
            'orientationOptions' => PageOrientation::options(),
            'canEditLayout' => $canEditLayout,
            'from' => $filters->from,
            'to' => $filters->to,
            'maxRangeDays' => $key->maxRangeDays(),
            'group' => $filters->group,
            'groupOptions' => ReportGroup::options($key->groups()),
            'staff' => $filters->staff === null ? '' : (string) $filters->staff,
            'staffOptions' => $this->report->staffOptions($property, $filters),
            'refundsOnly' => $filters->refundsOnly,
            'currency' => CurrencyData::forProperty($property),
        ];
    }
}
