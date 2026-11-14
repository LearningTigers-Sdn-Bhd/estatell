<?php

namespace App\Http\Controllers\Admin;

use App\Data\Platform\AdminSignInEventRowData;
use App\Enums\SignInResult;
use App\Http\Controllers\Controller;
use App\Services\Platform\SignInLogDirectory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;

class SignInEventController extends Controller
{
    /**
     * List the sign-in log with search, filters and sorting in the URL.
     */
    public function index(Request $request, SignInLogDirectory $signInLog): Response
    {
        return Inertia::render('admin/sign-in-events/index', [
            'events' => AdminSignInEventRowData::collect($signInLog->paginate($request), PaginatedDataCollection::class),
            'counts' => $signInLog->resultCounts(),
            'filters' => [
                'search' => $request->string('filter.search')->toString(),
                'result' => $request->string('filter.result')->toString(),
                'property' => $request->string('filter.property')->toString(),
                'from' => $request->string('filter.from')->toString(),
                'to' => $request->string('filter.to')->toString(),
            ],
            'sort' => $request->string('sort', '-occurred_at')->toString(),
            'results' => SignInResult::options(),
            'properties' => $signInLog->propertyOptions(),
        ]);
    }
}
