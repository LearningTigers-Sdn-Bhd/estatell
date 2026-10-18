<?php

namespace App\Http\Controllers\Portal;

use App\Data\Property\CurrencyData;
use App\Http\Controllers\Controller;
use App\Models\CompanyUser;
use App\Models\Property;
use App\Services\Portal\PortalProperties;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PortalController extends Controller
{
    /**
     * The company user's linked properties with the open balance and the arrivals of the next 7 days, and their
     * pending invitations.
     */
    public function index(Request $request, PortalProperties $portalProperties): Response
    {
        abort_unless($request->user()->is_company_user, 404);

        return Inertia::render('portal/index', $portalProperties->for($request->user()));
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
