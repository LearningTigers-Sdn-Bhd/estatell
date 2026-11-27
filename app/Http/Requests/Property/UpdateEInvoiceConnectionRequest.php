<?php

namespace App\Http\Requests\Property;

use App\Models\Property;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * E-invoicing › Connection: where e-invoices go and the MyInvois login. Signing fields (signature_enabled,
 * signing_certificate, signing_private_key, signing_passphrase) are deliberately not accepted: `DocumentSigner` does
 * not produce a signature LHDN accepts yet, so a crafted request must not be able to switch signing on.
 */
class UpdateEInvoiceConnectionRequest extends FormRequest
{
    /** The `can:settings.manage` route middleware authorizes this request. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'api_environment' => ['required', 'string', 'in:mock,sandbox,production'],
            'confirm_production' => ['nullable', 'boolean'],
            'client_id' => ['nullable', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Test (offline) is refused in production, and switching to live LHDN needs the confirm box.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $environment = $this->input('api_environment');

                if ($environment === 'mock' && app()->isProduction()) {
                    $validator->errors()->add('api_environment', __('Test (offline) is only for development. Choose the LHDN test site or live LHDN.'));
                }

                /** @var Property $property */
                $property = $this->route('property');

                if (
                    $environment === 'production'
                    && $property->eInvoiceSetting?->api_environment?->value !== 'production'
                    && ! $this->boolean('confirm_production')
                ) {
                    $validator->errors()->add('confirm_production', __('Tick the box to confirm that e-invoices will now be filed with LHDN for real.'));
                }
            },
        ];
    }
}
