<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DailyReportController extends Controller
{
    /**
     * The Daily report page. Until its tabs are built, it opens the Revenue report with the same query.
     */
    public function __invoke(Request $request, Property $property): RedirectResponse
    {
        return redirect()->route('reports.revenue', ['property' => $property->code, ...$request->except('tab')]);
    }
}
