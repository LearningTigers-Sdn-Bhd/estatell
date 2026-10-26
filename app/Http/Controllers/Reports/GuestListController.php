<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GuestListController extends Controller
{
    /**
     * The Guest list page. Until its tabs are built, it opens the Arrivals report with the same query.
     */
    public function __invoke(Request $request, Property $property): RedirectResponse
    {
        return redirect()->route('reports.arrivals', ['property' => $property->code, ...$request->except('tab')]);
    }
}
