<?php

namespace App\Http\Controllers\Onboarding;

use App\Actions\Onboarding\SaveOnboardingCompanies;
use App\Data\Onboarding\OnboardingCompanyData;
use App\Data\Property\CurrencyData;
use App\Enums\CompanyImportMode;
use App\Enums\CompanyType;
use App\Enums\OnboardingStep;
use App\Enums\SpreadsheetFormat;
use App\Http\Controllers\Controller;
use App\Http\Requests\CityLedger\ImportCompaniesRequest;
use App\Http\Requests\Onboarding\SaveCompaniesRequest;
use App\Models\Company;
use App\Models\Property;
use App\Services\CityLedger\CompanyImportReader;
use App\Services\CityLedger\CompanyImportTemplate;
use App\Services\Onboarding\OnboardingProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CompanyController extends Controller
{
    /**
     * The property's companies by name, with the types a row can take and the currency of the credit limit. A locked
     * step opens as a preview, and before Basics is saved there are no settings, so the currency is null.
     */
    public function edit(Property $property, OnboardingProgress $progress): Response
    {
        $blockingStep = $progress->blockingStep($property, OnboardingStep::Companies);

        return Inertia::render('onboarding/companies', [
            'companies' => $property->companies()->ordered()->get()
                ->map(fn (Company $company): OnboardingCompanyData => OnboardingCompanyData::fromModel($company)),
            'types' => CompanyType::options(),
            'currency' => CurrencyData::forProperty($property),
            'maxRows' => SaveCompaniesRequest::MAX_ROWS,
            'locked' => $blockingStep !== null,
            'blockedBy' => $blockingStep === null ? null : [
                'label' => $blockingStep->label(),
                'url' => $blockingStep->routeName() === null ? null : route($blockingStep->routeName(), ['property' => $property->code], absolute: false),
            ],
        ]);
    }

    public function update(SaveCompaniesRequest $request, Property $property, SaveOnboardingCompanies $saveCompanies): RedirectResponse
    {
        $cityLedgerTurnedOn = $saveCompanies($property, $request->user(), $request->rows());

        Inertia::flash('toast', ['type' => 'success', 'message' => $cityLedgerTurnedOn
            ? __('Companies saved. City ledger turned on in Payment methods.')
            : __('Companies saved.')]);

        $next = OnboardingStep::Companies->nextListed();

        return $next === null
            ? back()
            : to_route($next->routeName(), ['property' => $property->code]);
    }

    /**
     * Read an uploaded CSV or XLSX file into rows. Nothing is saved: the owner reviews the rows, then saves the table.
     */
    public function import(ImportCompaniesRequest $request, CompanyImportReader $reader): JsonResponse
    {
        return response()->json($reader->read($request->file('file')));
    }

    /**
     * The empty Simple file with the columns of the Companies table.
     */
    public function template(Property $property, SpreadsheetFormat $format, CompanyImportTemplate $template): BinaryFileResponse
    {
        return response()
            ->download($template->write($format, CompanyImportMode::Simple), "companies.{$format->value}")
            ->deleteFileAfterSend();
    }
}
