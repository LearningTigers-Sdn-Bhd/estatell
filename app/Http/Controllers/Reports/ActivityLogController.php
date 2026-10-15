<?php

namespace App\Http\Controllers\Reports;

use App\Data\Reports\ActivityLogRowData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ActivityLogRequest;
use App\Models\Property;
use App\Services\Reports\ActivityLog;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;

class ActivityLogController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(ActivityLogRequest $request, Property $property, ActivityLog $activityLog): Response
    {
        return Inertia::render('reports/activity-log', [
            'entries' => ActivityLogRowData::collect($activityLog->paginate($property, $request), PaginatedDataCollection::class),
            'search' => $request->string('filter.search')->toString(),
            'sort' => $request->string('sort', '-created_at')->toString(),
        ]);
    }
}
