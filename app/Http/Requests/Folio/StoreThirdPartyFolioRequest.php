<?php

namespace App\Http\Requests\Folio;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreThirdPartyFolioRequest extends FormRequest
{
    /**
     * The `can:folio.transfer` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the shape is checked here. The controller finds the guest inside the property.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'payer_guest_id' => ['required', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payer_guest_id.required' => __('Choose the guest who pays this folio.'),
        ];
    }
}
