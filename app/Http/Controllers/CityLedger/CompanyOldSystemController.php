<?php

namespace App\Http\Controllers\CityLedger;

use App\Actions\CityLedger\AddOldSystemCredit;
use App\Actions\CityLedger\AddOldSystemInvoice;
use App\Http\Controllers\Controller;
use App\Http\Requests\CityLedger\AddOldSystemCreditRequest;
use App\Http\Requests\CityLedger\AddOldSystemInvoiceRequest;
use App\Models\Company;
use App\Models\Property;
use App\Services\CityLedger\ArInvoiceRefusals;
use App\Services\CityLedger\ArPaymentRefusals;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Add an unpaid invoice or credit on account from the old PMS to a company, inside the migration window. Routes use
 * scoped bindings and `can:folio.manual`. See docs/15-city-ledger/05-old-system-balances.md.
 */
class CompanyOldSystemController extends Controller
{
    public function storeInvoice(AddOldSystemInvoiceRequest $request, Property $property, Company $company, AddOldSystemInvoice $addOldSystemInvoice, ArInvoiceRefusals $refusals): RedirectResponse
    {
        $result = $addOldSystemInvoice(
            $request->user(),
            $company,
            $request->string('number')->toString(),
            CarbonImmutable::parse($request->string('issued_on')->toString()),
            CarbonImmutable::parse($request->string('due_on')->toString()),
            $request->string('amount')->toString(),
            $request->string('reason')->toString(),
        );

        if ($result->isRefused()) {
            return back(fallback: $this->page($property, $company))->withErrors($refusals->errors((string) $result->refusal));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Old invoice :number added.', ['number' => $result->invoice?->number])]);

        return back(fallback: $this->page($property, $company));
    }

    public function storeCredit(AddOldSystemCreditRequest $request, Property $property, Company $company, AddOldSystemCredit $addOldSystemCredit, ArPaymentRefusals $refusals): RedirectResponse
    {
        $result = $addOldSystemCredit(
            $request->user(),
            $company,
            $request->string('reference')->toString(),
            CarbonImmutable::parse($request->string('received_on')->toString()),
            $request->string('amount')->toString(),
            $request->string('reason')->toString(),
            $request->string('idempotency_key')->toString(),
        );

        if ($result->isRefused()) {
            return back(fallback: $this->page($property, $company))->withErrors($refusals->errors((string) $result->refusal));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Old credit :reference added.', ['reference' => $result->payment?->reference])]);

        return back(fallback: $this->page($property, $company));
    }

    private function page(Property $property, Company $company): string
    {
        return route('finance.city-ledger.companies.show', ['property' => $property, 'company' => $company]);
    }
}
