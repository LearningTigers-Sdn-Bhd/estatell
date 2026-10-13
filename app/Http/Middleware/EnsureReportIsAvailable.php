<?php

namespace App\Http\Middleware;

use App\Enums\ReportKey;
use App\Models\Property;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 404 to a report whose feature is off for the property: its page, its exports and its layout routes. The
 * report comes from the `{report}` parameter, or from the route name for a report page. Other routes pass through.
 */
class EnsureReportIsAvailable
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $report = $request->route('report');
        $key = $report instanceof ReportKey
            ? $report
            : ReportKey::tryFrom((string) ($report ?? str_replace('reports.', '', (string) $request->route()->getName())));
        $property = $request->route('property');

        abort_unless($key === null || ($property instanceof Property && $key->isAvailable($property)), 404);

        return $next($request);
    }
}
