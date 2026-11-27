<?php

namespace App\Http\Controllers\Property;

use App\Actions\EInvoice\SaveEInvoiceSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Property\UpdateEInvoiceBusinessRequest;
use App\Http\Requests\Property\UpdateEInvoiceConnectionRequest;
use App\Http\Requests\Property\UpdateEInvoiceGeneralRequest;
use App\Models\Property;
use App\Models\PropertyEInvoiceSetting;
use App\Services\EInvoice\MalaysiaStates;
use App\Services\EInvoice\MyInvoisApiException;
use App\Services\EInvoice\MyInvoisClient;
use App\Services\EInvoice\MyInvoisClientFactory;
use App\Services\EInvoice\SupplierDetails;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › E-invoicing. Each tab is its own URL and saves only its own fields. The `einvoicing` route middleware
 * answers 404 while the feature is off. See docs/31-einvoice/01-lhdn-myinvois.md.
 */
class EInvoiceSettingsController extends Controller
{
    /**
     * General tab: switch e-invoicing on, send at check-out, monthly summary.
     */
    public function general(Property $property, SupplierDetails $supplierDetails): Response
    {
        return $this->renderTab($property, $supplierDetails, 'general');
    }

    /**
     * Connection tab: where e-invoices go and the MyInvois login.
     */
    public function connection(Property $property, SupplierDetails $supplierDetails): Response
    {
        return $this->renderTab($property, $supplierDetails, 'connection');
    }

    /**
     * Business details tab: the hotel details printed on every e-invoice. An empty field shows the property's value.
     */
    public function business(Property $property, SupplierDetails $supplierDetails): Response
    {
        return $this->renderTab($property, $supplierDetails, 'business', [
            'malaysiaStates' => MalaysiaStates::options(),
            'propertyDefaults' => [
                'brn' => $supplierDetails->for($property)['brn'],
                'legal_name' => $property->legal_name ?: $property->name,
                'tin' => $property->tin,
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
     * Switching on needs every saved business detail LHDN reads: nothing is made up for a missing one.
     */
    public function update(UpdateEInvoiceGeneralRequest $request, Property $property, SaveEInvoiceSettings $saveAction, SupplierDetails $supplierDetails): RedirectResponse
    {
        $validated = $request->validated();

        if ($validated['enabled'] && $supplierDetails->missing($property, $property->eInvoiceSetting) !== []) {
            throw ValidationException::withMessages([
                'enabled' => __('Complete the business details before switching e-invoicing on.'),
            ]);
        }

        return $this->save($property, $validated, $request, $saveAction);
    }

    public function updateConnection(UpdateEInvoiceConnectionRequest $request, Property $property, SaveEInvoiceSettings $saveAction): RedirectResponse
    {
        return $this->save($property, $request->validated(), $request, $saveAction);
    }

    /**
     * While e-invoicing is on, a business detail LHDN needs cannot be cleared.
     */
    public function updateBusiness(UpdateEInvoiceBusinessRequest $request, Property $property, SaveEInvoiceSettings $saveAction, SupplierDetails $supplierDetails): RedirectResponse
    {
        $validated = $request->validated();
        $setting = $property->eInvoiceSetting;

        if ($setting?->enabled) {
            $problems = $supplierDetails->missing($property, $setting->replicate()->forceFill($validated));

            if ($problems !== []) {
                throw ValidationException::withMessages($problems);
            }
        }

        return $this->save($property, $validated, $request, $saveAction);
    }

    public function test(Property $property): RedirectResponse
    {
        $setting = $property->eInvoiceSetting;
        if ($setting === null || ! MyInvoisClientFactory::isReady($setting)) {
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

    /**
     * @param  array<string, mixed>  $validated
     */
    private function save(Property $property, array $validated, Request $request, SaveEInvoiceSettings $saveAction): RedirectResponse
    {
        $saveAction($property, $validated, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('E-invoice settings saved.')]);

        return back();
    }

    /**
     * Every tab draws the Status rail, so each gets the saved setting and what still stops e-invoices from going out.
     *
     * @param  'general'|'connection'|'business'  $tab
     * @param  array<string, mixed>  $tabProps
     */
    private function renderTab(Property $property, SupplierDetails $supplierDetails, string $tab, array $tabProps = []): Response
    {
        $setting = $property->eInvoiceSetting;

        return Inertia::render('settings/e-invoice/edit', [
            'tab' => $tab,
            'setting' => $setting === null ? null : $this->settingProps($setting),
            'problems' => $supplierDetails->missing($property, $setting),
            ...$tabProps,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function settingProps(PropertyEInvoiceSetting $setting): array
    {
        return [
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
        ];
    }
}
