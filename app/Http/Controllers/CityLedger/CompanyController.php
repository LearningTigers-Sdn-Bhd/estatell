<?php

namespace App\Http\Controllers\CityLedger;

use App\Actions\CityLedger\CreateCompany;
use App\Actions\CityLedger\SyncCompanyRatePlans;
use App\Actions\CityLedger\UpdateCompany;
use App\Actions\CityLedger\UpdateCompanyBilling;
use App\Data\CityLedger\CompanyData;
use App\Data\CityLedger\CompanyRowData;
use App\Data\CityLedger\CreditExposureData;
use App\Data\Property\CurrencyData;
use App\Data\Rates\RatePlanData;
use App\Enums\CompanyStatus;
use App\Enums\CompanyType;
use App\Enums\PermissionEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\CityLedger\CompanyRequest;
use App\Http\Requests\CityLedger\UpdateCompanyRatePlansRequest;
use App\Models\Company;
use App\Models\Property;
use App\Models\RatePlan;
use App\Services\CityLedger\ArInvoiceDirectory;
use App\Services\CityLedger\ArPaymentLedger;
use App\Services\CityLedger\CompanyPortalState;
use App\Services\CityLedger\CreditExposure;
use App\Services\CityLedger\StatementBuilder;
use App\Services\Guests\CountryOptions;
use App\Services\Portal\PortalRatePlans;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    /**
     * Every company of the property in name order. The list is bounded, so it loads once and filters in the browser.
     * The add company sheet opens on this page.
     */
    public function index(Request $request, Property $property, CreditExposure $creditExposure): Response
    {
        $companies = $property->companies()->ordered()->get();
        $figures = $creditExposure->forCompanies($companies);

        return Inertia::render('finance/city-ledger/companies/index', [
            'companies' => $companies
                ->map(fn (Company $company): CompanyRowData => CompanyRowData::fromModel($company, $creditExposure->isOverLimit($figures[$company->id]))),
            'types' => CompanyType::options(),
            'statuses' => CompanyStatus::options(),
            'currency' => CurrencyData::forProperty($property),
            'canManage' => $request->user()->can(PermissionEnum::CompanyManage->value),
        ]);
    }

    public function store(CompanyRequest $request, Property $property, CreateCompany $createCompany): RedirectResponse
    {
        $company = $createCompany($request->user(), $property, $request->companyAttributes());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name added.', ['name' => $company->name])]);
        $loginCreated = $this->newLoginWithTempPassword($company, []);
        Inertia::flash('portalLoginCreated', $loginCreated);

        return to_route($loginCreated ? 'finance.city-ledger.companies.portal' : 'finance.city-ledger.companies.show', ['property' => $property, 'company' => $company]);
    }

    /**
     * Company page, Details tab: name, type, contact and registration numbers.
     */
    public function show(Request $request, Property $property, Company $company): Response
    {
        return $this->renderTab($request, $property, $company, 'details', [
            'types' => CompanyType::options(),
            'countries' => app(CountryOptions::class)->options(),
        ]);
    }

    /**
     * Company page, Credit tab: credit limit, payment terms and direct billing.
     */
    public function credit(Request $request, Property $property, Company $company): Response
    {
        return $this->renderTab($request, $property, $company, 'credit');
    }

    /**
     * Company page, Rates tab: the derived plans only this company can book.
     */
    public function rates(Request $request, Property $property, Company $company): Response
    {
        return $this->renderTab($request, $property, $company, 'rates', [
            'ratePlans' => $property->ratePlans()->active()->whereNotNull('parent_rate_plan_id')->with('roomTypes', 'parent')->ordered()->get()
                ->map(fn (RatePlan $ratePlan): RatePlanData => RatePlanData::fromModel($ratePlan)),
            'linkedPlanIds' => $company->ratePlans()->pluck('rate_plans.id')->all(),
        ]);
    }

    /**
     * Company page, Portal tab: the company's one portal login and its status.
     */
    public function portal(Request $request, Property $property, Company $company): Response
    {
        return $this->renderTab($request, $property, $company, 'portal');
    }

    /**
     * Company page, Payments tab: the company's AR payments, the invoices that still owe money and the methods.
     */
    public function payments(Request $request, Property $property, Company $company, ArPaymentLedger $ledger): Response
    {
        return $this->renderTab($request, $property, $company, 'payments', [
            'payments' => $ledger->payments($company, $request->user()),
            'openInvoices' => $ledger->openInvoices($company),
            'methods' => $ledger->methods($company),
            'canRecordPayment' => $request->user()->can(PermissionEnum::CityLedgerPayment->value),
            'canAllocate' => $request->user()->can(PermissionEnum::CityLedgerAllocate->value),
        ]);
    }

    /**
     * Company page, Statement tab: the statement of account between two dates.
     */
    public function statement(Request $request, Property $property, Company $company, StatementBuilder $builder, ArInvoiceDirectory $directory): Response
    {
        [$from, $to] = CompanyStatementController::range($request, $directory->today($property));

        return $this->renderTab($request, $property, $company, 'statement', [
            'statement' => $builder->build($company, $from, $to),
        ]);
    }

    public function updateRates(UpdateCompanyRatePlansRequest $request, Property $property, Company $company, SyncCompanyRatePlans $syncCompanyRatePlans, PortalRatePlans $portalRatePlans): RedirectResponse
    {
        if ($portalRatePlans->wouldEmptySelectedPlan($company, $request->ratePlanIds())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This plan is Selected for the portal and needs a company.')]);

            return to_route('finance.city-ledger.companies.rates', ['property' => $property, 'company' => $company]);
        }

        Inertia::flash('toast', $syncCompanyRatePlans($request->user(), $company, $request->ratePlanIds())
            ? ['type' => 'success', 'message' => __('Rates saved.')]
            : ['type' => 'error', 'message' => __('The rate plans changed. Reload the page and try again.')]);

        return to_route('finance.city-ledger.companies.rates', ['property' => $property, 'company' => $company]);
    }

    public function update(CompanyRequest $request, Property $property, Company $company, string $section, UpdateCompany $updateCompany): RedirectResponse
    {
        $linksBefore = $company->companyUsers()->pluck('id')->all();

        $updateCompany($request->user(), $company, $request->companyAttributes());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name saved.', ['name' => $company->refresh()->name])]);
        Inertia::flash('portalLoginCreated', $this->newLoginWithTempPassword($company, $linksBefore));

        return to_route($section === 'details' ? 'finance.city-ledger.companies.show' : "finance.city-ledger.companies.{$section}", ['property' => $property, 'company' => $company]);
    }

    /**
     * The tax numbers and billing address form on the Details tab. Refused when the company manages them itself.
     */
    public function updateBilling(CompanyRequest $request, Property $property, Company $company, UpdateCompanyBilling $updateCompanyBilling): RedirectResponse
    {
        Inertia::flash('toast', $updateCompanyBilling($request->user(), $company, $request->companyAttributes())
            ? ['type' => 'success', 'message' => __('Tax numbers and billing address saved.')]
            : ['type' => 'error', 'message' => __('The company manages these details in the company portal.')]);

        return to_route('finance.city-ledger.companies.show', ['property' => $property, 'company' => $company]);
    }

    /**
     * True when the save made a new login with a temp password, so the page can offer Copy message at once.
     *
     * @param  list<int>  $linksBefore  Ids of the company's links before the save.
     */
    private function newLoginWithTempPassword(Company $company, array $linksBefore): bool
    {
        return $company->companyUsers()->active()
            ->whereNotIn('id', $linksBefore)
            ->whereHas('user', fn ($query) => $query->whereNotNull('temporary_password'))
            ->exists();
    }

    /**
     * Each tab has its own URL, so the open tab survives a refresh. Only the open tab's options are sent.
     * Staff without `company.manage` see the fields read-only.
     *
     * @param  'details'|'credit'|'rates'|'portal'|'payments'|'statement'  $tab
     * @param  array<string, mixed>  $tabProps
     */
    private function renderTab(Request $request, Property $property, Company $company, string $tab, array $tabProps = []): Response
    {
        $creditExposure = app(CreditExposure::class);
        $figures = $creditExposure->forCompany($company);

        return Inertia::render('finance/city-ledger/companies/show', [
            'company' => CompanyData::fromModel(
                $company,
                CreditExposureData::fromFigures($figures, $creditExposure->isOverLimit($figures)),
                $tab === 'portal' ? app(CompanyPortalState::class)->for($company) : null,
            ),
            'tab' => $tab,
            'currency' => CurrencyData::forProperty($property),
            'canManage' => $request->user()->can(PermissionEnum::CompanyManage->value),
            ...$tabProps,
        ]);
    }
}
