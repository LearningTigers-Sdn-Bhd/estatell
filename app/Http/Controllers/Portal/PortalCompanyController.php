<?php

namespace App\Http\Controllers\Portal;

use App\Actions\CityLedger\UpdatePortalCompanyDetails;
use App\Data\Portal\PortalCompanyData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\UpdatePortalCompanyRequest;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Property;
use App\Services\Guests\CountryOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The company's own contact, tax numbers and billing address at the property. Each property has its own company
 * record, so a save changes only the property in the URL. A Suspended company can still save.
 * See docs/23-company-portal/01-company-portal.md#company-details.
 */
class PortalCompanyController extends Controller
{
    /**
     * The company details page.
     */
    public function show(Request $request, Property $property, CountryOptions $countryOptions): Response
    {
        return Inertia::render('portal/company', [
            'propertyCode' => $property->code,
            'company' => PortalCompanyData::fromModel($this->company($request)),
            'countries' => $countryOptions->options(),
        ]);
    }

    /**
     * Save the company details. Refused input shows on its field; a saved change is logged as `company.updated`.
     */
    public function update(UpdatePortalCompanyRequest $request, Property $property, UpdatePortalCompanyDetails $updatePortalCompanyDetails): RedirectResponse
    {
        $updatePortalCompanyDetails($request->user(), $this->company($request), $request->detailAttributes());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Company details saved.')]);

        return to_route('portal.company', ['property' => $property->code]);
    }

    /**
     * The company of the active link that `EnsurePortalAccess` checked.
     */
    private function company(Request $request): Company
    {
        $link = $request->attributes->get('portalLink');

        abort_unless($link instanceof CompanyUser, 404);

        return $link->company;
    }
}
