<?php

namespace App\Http\Controllers\Reports;

use App\Data\Reports\FolioLedgerRow;
use App\Enums\PageOrientation;
use App\Enums\PermissionEnum;
use App\Enums\ReportKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportFiltersRequest;
use App\Models\Property;
use App\Services\Reports\FolioLedgerReport;
use App\Services\Reports\ReportLayoutResolver;
use App\Services\Reports\ReportPage;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;

class FolioLedgerReportController extends Controller
{
    /**
     * The Folio ledger page: every folio line posted from `?from` to `?to`, optionally for one `?code`, sorted and paged on the server.
     */
    public function __invoke(ReportFiltersRequest $request, Property $property, ReportPage $reportPage, ReportLayoutResolver $layoutResolver, FolioLedgerReport $report): Response
    {
        $key = ReportKey::FolioLedger;
        $filters = $request->filters();
        $layout = $layoutResolver->resolve($property, $key);
        $allRows = $report->rows($property, $filters);

        [$rows, $sort] = $reportPage->paginate(
            rows: $allRows,
            sort: $request->string('sort')->toString(),
            defaultSort: $key->defaultSort(),
            columnKeys: $layout->visibleKeys(),
            page: max(1, $request->integer('page', 1)),
            path: $request->url(),
        );

        return Inertia::render('reports/folio-ledger', [
            'rows' => FolioLedgerRow::collect($rows->withQueryString(), PaginatedDataCollection::class),
            'totals' => $key->totals($allRows),
            'sort' => $sort,
            'layout' => $layout,
            'orientationOptions' => PageOrientation::options(),
            'canEditLayout' => $request->user()->can(PermissionEnum::SettingsManage->value),
            'from' => $filters->from,
            'to' => $filters->to,
            'maxRangeDays' => $key->maxRangeDays(),
            'code' => $filters->code,
            'codeOptions' => $report->codeOptions($property),
            'currency' => $property->settings?->currency,
        ]);
    }
}
