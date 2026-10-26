<?php

namespace App\Http\Controllers\Reports;

use App\Enums\PermissionEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportFiltersRequest;
use App\Models\Property;
use App\Services\Reports\PaymentsReportPage;
use Inertia\Inertia;
use Inertia\Response;

class PaymentsReportController extends Controller
{
    /**
     * The Payments report page: the folio and city ledger payments and refunds from `?from` to `?to`, sorted and paged on the server, with the summary by method or by staff.
     */
    public function __invoke(ReportFiltersRequest $request, Property $property, PaymentsReportPage $page): Response
    {
        return Inertia::render('reports/payments', $page->props(
            property: $property,
            filters: $request->filters(),
            sort: $request->string('sort')->toString(),
            page: max(1, $request->integer('page', 1)),
            path: $request->url(),
            canEditLayout: $request->user()->can(PermissionEnum::SettingsManage->value),
        ));
    }
}
