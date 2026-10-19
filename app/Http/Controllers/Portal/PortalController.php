<?php

namespace App\Http\Controllers\Portal;

use App\Data\Property\CurrencyData;
use App\Http\Controllers\Controller;
use App\Models\CompanyUser;
use App\Models\Property;
use App\Services\Portal\PortalArrivals;
use App\Services\Portal\PortalProperties;
use App\Services\Portal\PortalRecentBookings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PortalController extends Controller
{
    /**
     * The dashboard: the pending invitations and the arrivals of the next 7 days across the linked properties.
     */
    public function index(Request $request, PortalProperties $portalProperties, PortalArrivals $portalArrivals, PortalRecentBookings $recentBookings): Response
    {
        abort_unless($request->user()->is_company_user, 404);

        return Inertia::render('portal/index', [
            'invitations' => $portalProperties->invitations($request->user()),
            'arrivals' => $portalArrivals->for($request->user()),
            'properties' => array_slice($portalProperties->for($request->user()), 0, PortalProperties::DASHBOARD_LIMIT),
            'recentBookings' => $recentBookings->for($request->user()),
            'arrivalDays' => PortalProperties::ARRIVAL_DAYS,
        ]);
    }

    /**
     * The company user's linked properties with the open balance and the arrivals of the next 7 days.
     */
    public function properties(Request $request, PortalProperties $portalProperties): Response
    {
        abort_unless($request->user()->is_company_user, 404);

        return Inertia::render('portal/properties', ['properties' => $portalProperties->for($request->user())]);
    }

    /**
     * The small dashboard of one property: the company, its status and its open balance. `EnsurePortalAccess` has
     * checked the link.
     */
    public function show(Request $request, Property $property, PortalProperties $portalProperties): Response
    {
        $link = $request->attributes->get('portalLink');
        $property->loadMissing('settings');

        abort_unless($link instanceof CompanyUser, 404);

        return Inertia::render('portal/show', [
            'companyName' => $link->company->name,
            'companyStatus' => $link->company->status->label(),
            'companySuspended' => $link->company->status->value === 'suspended',
            'propertyName' => $property->name,
            'propertyCode' => $property->code,
            'openBalance' => $portalProperties->openBalance($link->company_id),
            'currency' => CurrencyData::forProperty($property),
        ]);
    }
}
