<?php

namespace App\Http\Controllers\Property;

use App\Actions\EInvoice\SaveEInvoiceSettings;
use App\Enums\EInvoiceEnvironment;
use App\Features\EInvoicing;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyEInvoiceSetting;
use App\Services\EInvoice\MalaysiaStates;
use App\Services\EInvoice\MyInvoisApiException;
use App\Services\EInvoice\MyInvoisClient;
use App\Services\EInvoice\MyInvoisClientFactory;
use App\Services\EInvoice\SupplierDetails;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature;

class EInvoiceSettingsController extends Controller
{
    public function edit(Property $property, SupplierDetails $supplierDetails): Response|RedirectResponse
    {
        if (! Feature::for($property)->active(EInvoicing::class)) {
            abort(404);
        }

        $setting = $property->eInvoiceSetting;

        return Inertia::render('settings/e-invoice/edit', [
            'setting' => $setting === null ? null : [
                'enabled' => $setting->enabled,
                'api_environment' => $setting->api_environment->value,
                'client_id' => (string) $setting->client_id,
                'has_client_secret' => ! empty($setting->client_secret),
                'supplier_name' => $setting->supplier_name,
                'supplier_tin' => $setting->supplier_tin,
                'supplier_brn' => $setting->supplier_brn,
                'supplier_sst_registration' => $setting->supplier_sst_registration,
                'supplier_ttx_registration' => $setting->supplier_ttx_registration,
                'supplier_msic_code' => $setting->supplier_msic_code,
                'supplier_business_description' => $setting->supplier_business_description,
                'supplier_email' => $setting->supplier_email,
                'supplier_phone' => $setting->supplier_phone,
                'supplier_address_line1' => $setting->supplier_address_line1,
                'supplier_address_line2' => $setting->supplier_address_line2,
                'supplier_city' => $setting->supplier_city,
                'supplier_postal_code' => $setting->supplier_postal_code,
                'supplier_state_code' => $setting->supplier_state_code,
                'auto_submit_on_checkout' => $setting->auto_submit_on_checkout,
                'consolidation_enabled' => $setting->consolidation_enabled,
                'effective_from' => $setting->effective_from?->toIso8601String(),
            ],
            // E-invoicing already on with gaps, for example switched on before details were checked.
            'problems' => $setting?->enabled ? $supplierDetails->missing($property) : [],
            'environmentOptions' => EInvoiceEnvironment::options(),
            'malaysiaStates' => MalaysiaStates::options(),
            'propertyDefaults' => [
                'tin' => $property->tin,
                'registration_number' => $property->registration_number,
                'brn' => $supplierDetails->for($property)['brn'],
                'legal_name' => $property->legal_name ?: $property->name,
                'msic_code' => $property->msic_code ?: '55101',
                'business_description' => $property->business_description ?: 'Hotel and accommodation services',
                'address_line1' => $property->address_line1,
                'address_line2' => $property->address_line2,
                'city' => $property->city,
                'postal_code' => $property->postal_code,
                'state_code' => MalaysiaStates::codeFor($property->state_code) ?? '',
                'contact_email' => $property->contact_email,
                'contact_phone' => $property->contact_phone,
            ],
        ]);
    }

    /**
     * Signing fields (signature_enabled, signing_certificate, signing_private_key,
     * signing_passphrase) are deliberately not accepted. DocumentSigner does not
     * produce a signature LHDN accepts yet, so a crafted request must not be able
     * to switch signing on. Unsigned v1.0 documents are what LHDN accepts today.
     */
    public function update(Property $property, Request $request, SaveEInvoiceSettings $saveAction, SupplierDetails $supplierDetails): RedirectResponse
    {
        if (! Feature::for($property)->active(EInvoicing::class)) {
            abort(404);
        }

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'api_environment' => ['required', 'string', 'in:mock,sandbox,production'],
            'confirm_production' => ['nullable', 'boolean'],
            'client_id' => ['nullable', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:255'],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'supplier_tin' => ['nullable', 'string', 'max:30'],
            'supplier_brn' => ['nullable', 'string', 'max:40'],
            'supplier_sst_registration' => ['nullable', 'string', 'max:30'],
            'supplier_ttx_registration' => ['nullable', 'string', 'max:30'],
            'supplier_msic_code' => ['nullable', 'string', 'max:5'],
            'supplier_business_description' => ['nullable', 'string', 'max:255'],
            'supplier_email' => ['nullable', 'email', 'max:255'],
            'supplier_phone' => ['nullable', 'string', 'max:30'],
            'supplier_address_line1' => ['nullable', 'string', 'max:255'],
            'supplier_address_line2' => ['nullable', 'string', 'max:255'],
            'supplier_city' => ['nullable', 'string', 'max:100'],
            'supplier_postal_code' => ['nullable', 'string', 'max:20'],
            'supplier_state_code' => ['nullable', 'string', 'max:2'],
            'auto_submit_on_checkout' => ['nullable', 'boolean'],
            'consolidation_enabled' => ['nullable', 'boolean'],
        ]);

        if ($validated['api_environment'] === 'mock' && app()->isProduction()) {
            throw ValidationException::withMessages([
                'api_environment' => __('Test (offline) is only for development. Choose the LHDN test site or live LHDN.'),
            ]);
        }

        $currentSetting = $property->eInvoiceSetting;
        if (
            $validated['api_environment'] === 'production' &&
            $currentSetting?->api_environment?->value !== 'production' &&
            empty($validated['confirm_production'])
        ) {
            throw ValidationException::withMessages([
                'confirm_production' => __('Tick the box to confirm that e-invoices will now be filed with LHDN for real.'),
            ]);
        }

        // Switching on needs every detail LHDN reads: nothing is made up for a missing one.
        if ($validated['enabled']) {
            $draft = ($property->eInvoiceSetting ?? new PropertyEInvoiceSetting)->replicate()->forceFill(Arr::only($validated, SaveEInvoiceSettings::SUPPLIER_FIELDS));
            $problems = $supplierDetails->missing($property, $draft);
            if ($problems !== []) {
                throw ValidationException::withMessages([...$problems, 'enabled' => __('Complete the hotel details below before switching e-invoicing on.')]);
            }
        }

        $saveAction($property, $validated, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('E-invoice settings saved.')]);

        return back();
    }

    public function test(Property $property): RedirectResponse
    {
        if (! Feature::for($property)->active(EInvoicing::class)) {
            abort(404);
        }

        $setting = $property->eInvoiceSetting;
        if ($setting === null || ! $setting->isReadyToSubmit()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Enter and save the MyInvois Client ID and Client secret first.')]);

            return back();
        }

        try {
            $client = MyInvoisClientFactory::forSetting($setting);
            if ($client instanceof MyInvoisClient) {
                $client->getAccessToken();
            }

            Inertia::flash('toast', ['type' => 'success', 'message' => __('LHDN accepted the MyInvois login.')]);

            return back();
        } catch (MyInvoisApiException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('LHDN refused the MyInvois login. Check the Client ID and Client secret.'), 'description' => $e->getMessage()]);

            return back();
        }
    }
}
