<?php

namespace App\Http\Requests\Concierge;

use App\Services\EInvoice\MalaysiaStates;
use App\Services\Guests\CountryOptions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RequestEInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The buyer fields of the staff Issue e-invoice form, for a person: NRIC or passport, the city and country, and a
     * Malaysian state when the address is in Malaysia.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'id_type' => ['required', Rule::in(['NRIC', 'PASSPORT'])],
            'id_value' => ['required', 'string', 'max:50'],
            'tin' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'state_code' => ['required_if:country_code,MY', 'nullable', Rule::in(array_keys(MalaysiaStates::CODES))],
            'country_code' => ['required', Rule::in(app(CountryOptions::class)->codes())],
        ];
    }

    /**
     * The buyer snapshot `SubmitFolioEInvoice` takes. A buyer outside Malaysia has no Malaysian state.
     *
     * @return array<string, mixed>
     */
    public function buyer(): array
    {
        $buyer = $this->validated();

        if ($buyer['country_code'] !== 'MY') {
            $buyer['state_code'] = MalaysiaStates::NOT_APPLICABLE;
        }

        return $buyer;
    }
}
