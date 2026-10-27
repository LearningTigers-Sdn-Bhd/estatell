<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class InHouseReportController extends Controller
{
    /**
     * The old in-house URL: it opens the InHouse tab of the Guest list with the same query.
     */
    public function __invoke(Request $request, Property $property): RedirectResponse
    {
        return redirect()->route('reports.guest-list', ['property' => $property->code, ...$request->query(), 'tab' => 'in-house']);
    }
}
