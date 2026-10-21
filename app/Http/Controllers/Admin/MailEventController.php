<?php

namespace App\Http\Controllers\Admin;

use App\Data\Platform\AdminMailEventRowData;
use App\Enums\MailStatus;
use App\Http\Controllers\Controller;
use App\Models\MailEvent;
use App\Services\Platform\MailLogDirectory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;

class MailEventController extends Controller
{
    /**
     * List the mail log with search, filters and sorting in the URL.
     */
    public function index(Request $request, MailLogDirectory $mailLog): Response
    {
        return Inertia::render('admin/mail-events/index', [
            'events' => AdminMailEventRowData::collect($mailLog->paginate($request), PaginatedDataCollection::class),
            'counts' => $mailLog->statusCounts(),
            'filters' => [
                'search' => $request->string('filter.search')->toString(),
                'status' => $request->string('filter.status')->toString(),
                'from' => $request->string('filter.from')->toString(),
                'to' => $request->string('filter.to')->toString(),
            ],
            'sort' => $request->string('sort', '-sent_at')->toString(),
            'statuses' => MailStatus::options(),
        ]);
    }

    /**
     * Show one mail log row.
     */
    public function show(MailEvent $mailEvent): Response
    {
        return Inertia::render('admin/mail-events/show', [
            'event' => AdminMailEventRowData::from($mailEvent->load('property:id,name')),
        ]);
    }
}
