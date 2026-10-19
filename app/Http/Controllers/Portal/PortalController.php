<?php

namespace App\Http\Controllers\Portal;

use App\Data\Property\CurrencyData;
use App\Data\Shared\BadgeData;
use App\Enums\CompanyStatus;
use App\Http\Controllers\Controller;
use App\Models\CompanyUser;
use App\Models\Property;
use App\Services\Portal\PortalArrivals;
use App\Services\Portal\PortalProperties;
use App\Services\Portal\PortalPropertyDashboard;
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
     * The dashboard of one property: the company, four figures, the upcoming arrivals, the open invoices and the newest
     * bookings, all for the company of the link. `EnsurePortalAccess` has checked the link.
     */
    public function show(Request $request, Property $property, PortalPropertyDashboard $dashboard, PortalArrivals $portalArrivals, PortalRecentBookings $recentBookings): Response
    {
        $link = $request->attributes->get('portalLink');
        $property->loadMissing('settings');

        abort_unless($link instanceof CompanyUser, 404);

        $company = $link->company;
        $arrivals = $portalArrivals->for($request->user(), $property->id);
        $openInvoices = $dashboard->openInvoices($property, $company);

        return Inertia::render('portal/show', [
            'propertyName' => $property->name,
            'propertyCode' => $property->code,
            'companyName' => $company->name,
            'companyStatus' => BadgeData::fromCompanyStatus($company->status),
            'companySuspended' => $company->status === CompanyStatus::Suspended,
            'cancelCutoffDays' => $company->portal_cancel_cutoff_days,
            'stats' => $dashboard->stats($company, $openInvoices, count($arrivals)),
            'arrivals' => array_slice($arrivals, 0, PortalPropertyDashboard::LIST_LIMIT),
            'openInvoices' => array_slice($openInvoices, 0, PortalPropertyDashboard::LIST_LIMIT),
            'recentBookings' => $recentBookings->for($request->user(), $property->id),
            'currency' => CurrencyData::forProperty($property),
        ]);
    }
}
