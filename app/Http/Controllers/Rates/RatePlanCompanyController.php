<?php

namespace App\Http\Controllers\Rates;

use App\Actions\Rates\AddRatePlanCompanies;
use App\Actions\Rates\RemoveRatePlanCompany;
use App\Actions\Rates\SetRatePlanCompanyAccess;
use App\Actions\Rates\UpdateRatePlanCompany;
use App\Data\Rates\RatePlanCompaniesData;
use App\Data\Rates\RatePlanCompanyCandidateData;
use App\Data\Rates\RatePlanData;
use App\Enums\CompanyAccess;
use App\Enums\CompanyRateMarket;
use App\Enums\CompanyStatus;
use App\Enums\PermissionEnum;
use App\Enums\PricingBasis;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rates\AddRatePlanCompaniesRequest;
use App\Http\Requests\Rates\RatePlanCompanyAccessRequest;
use App\Http\Requests\Rates\RatePlanCompanyRowRequest;
use App\Models\Company;
use App\Models\Property;
use App\Models\RatePlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Companies tab of the rate plan editor: who can use the rate. Day use plans are front desk only, so every
 * endpoint is a 404 for them. See docs/08-rates/01-rate-plans.md#who-can-use-this-rate.
 */
class RatePlanCompanyController extends Controller
{
    public function edit(Request $request, Property $property, RatePlan $ratePlan): Response
    {
        abort_if($ratePlan->pricing_basis === PricingBasis::DayUse, 404);

        $canManageCompanies = $request->user()->can(PermissionEnum::CompanyManage->value);
        $ratePlan->load('roomTypes', 'companies.portalLink.user');

        return Inertia::render('settings/rate-plans/companies', [
            'ratePlan' => RatePlanData::fromModel($ratePlan),
            'access' => RatePlanCompaniesData::fromModel($ratePlan),
            'accessOptions' => CompanyAccess::options(),
            'marketOptions' => CompanyRateMarket::options(),
            'candidates' => $canManageCompanies && $ratePlan->company_access === CompanyAccess::Selected
                ? array_values($property->companies()
                    ->where('status', CompanyStatus::Active)
                    ->whereNotIn('id', $ratePlan->companies->pluck('id'))
                    ->ordered()->get()
                    ->map(RatePlanCompanyCandidateData::fromModel(...))->all())
                : [],
            'canManageCompanies' => $canManageCompanies,
            'canViewCompanies' => $request->user()->can(PermissionEnum::CompanyView->value),
        ]);
    }

    public function update(RatePlanCompanyAccessRequest $request, Property $property, RatePlan $ratePlan, SetRatePlanCompanyAccess $setRatePlanCompanyAccess): RedirectResponse
    {
        abort_if($ratePlan->pricing_basis === PricingBasis::DayUse, 404);

        $refusal = $setRatePlanCompanyAccess($request->user(), $ratePlan, $request->companyAccess(), $request->atDesk(), $request->inPortal(), $request->companyMarket());

        return $this->back($property, $ratePlan, $refusal, __('Company access saved.'), [
            SetRatePlanCompanyAccess::REFUSAL_NOTHING_ON => __('Turn on Front desk or Portal.'),
        ]);
    }

    public function store(AddRatePlanCompaniesRequest $request, Property $property, RatePlan $ratePlan, AddRatePlanCompanies $addRatePlanCompanies): RedirectResponse
    {
        abort_if($ratePlan->pricing_basis === PricingBasis::DayUse, 404);

        $refusal = $addRatePlanCompanies($request->user(), $ratePlan, $request->companyIds());

        return $this->back($property, $ratePlan, $refusal, trans_choice(':count company added.|:count companies added.', count($request->companyIds())), [
            AddRatePlanCompanies::REFUSAL_NOT_SELECTED => __('Pick Only these companies first.'),
            AddRatePlanCompanies::REFUSAL_COMPANY_UNAVAILABLE => __('Only Active companies can be added. Reload the page and try again.'),
        ]);
    }

    public function updateRow(RatePlanCompanyRowRequest $request, Property $property, RatePlan $ratePlan, Company $company, UpdateRatePlanCompany $updateRatePlanCompany): RedirectResponse
    {
        abort_if($ratePlan->pricing_basis === PricingBasis::DayUse, 404);

        $refusal = $updateRatePlanCompany($request->user(), $ratePlan, $company, $request->boolean('at_desk'), $request->boolean('in_portal'));

        return $this->back($property, $ratePlan, $refusal, __('Saved.'), [
            UpdateRatePlanCompany::REFUSAL_NOTHING_ON => __('Turn on at least one, or remove the company.'),
        ]);
    }

    public function destroy(Request $request, Property $property, RatePlan $ratePlan, Company $company, RemoveRatePlanCompany $removeRatePlanCompany): RedirectResponse
    {
        abort_if($ratePlan->pricing_basis === PricingBasis::DayUse, 404);

        $refusal = $removeRatePlanCompany($request->user(), $ratePlan, $company);

        return $this->back($property, $ratePlan, $refusal, __(':name removed.', ['name' => $company->name]), []);
    }

    /**
     * Redirect to the tab with a success toast, or the error toast for the refusal code.
     *
     * @param  array<string, string>  $messages  Toast text by refusal code. Any other code means the plan changed.
     */
    private function back(Property $property, RatePlan $ratePlan, ?string $refusal, string $success, array $messages): RedirectResponse
    {
        Inertia::flash('toast', $refusal === null
            ? ['type' => 'success', 'message' => $success]
            : ['type' => 'error', 'message' => $messages[$refusal] ?? __('The rate plan changed. Reload the page and try again.')]);

        return to_route('settings.rate-plans.companies.edit', ['property' => $property, 'ratePlan' => $ratePlan]);
    }
}
