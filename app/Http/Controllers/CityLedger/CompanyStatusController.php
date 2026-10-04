<?php

namespace App\Http\Controllers\CityLedger;

use App\Actions\CityLedger\ReactivateCompany;
use App\Actions\CityLedger\SuspendCompany;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CompanyStatusController extends Controller
{
    public function suspend(Request $request, Property $property, Company $company, SuspendCompany $suspendCompany): RedirectResponse
    {
        Inertia::flash('toast', $suspendCompany($request->user(), $company)
            ? ['type' => 'success', 'message' => __(':name suspended.', ['name' => $company->name])]
            : ['type' => 'error', 'message' => __('This company is already suspended.')]);

        return back(fallback: route('finance.city-ledger.companies.show', ['property' => $property, 'company' => $company]));
    }

    public function reactivate(Request $request, Property $property, Company $company, ReactivateCompany $reactivateCompany): RedirectResponse
    {
        Inertia::flash('toast', $reactivateCompany($request->user(), $company)
            ? ['type' => 'success', 'message' => __(':name reactivated.', ['name' => $company->name])]
            : ['type' => 'error', 'message' => __('This company is already active.')]);

        return back(fallback: route('finance.city-ledger.companies.show', ['property' => $property, 'company' => $company]));
    }
}
