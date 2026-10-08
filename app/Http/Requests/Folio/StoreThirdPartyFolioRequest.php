<?php

namespace App\Http\Requests\Folio;

use App\Enums\CompanyStatus;
use App\Enums\PermissionEnum;
use App\Models\Property;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreThirdPartyFolioRequest extends FormRequest
{
    /**
     * The `can:folio.transfer` route middleware authorizes this request. A company payer also needs `company.view`.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The payer is a guest or an Active company of the property, never both. The controller finds the guest inside the
     * property.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $property = $this->route('property');

        return [
            'payer_guest_id' => ['required_without:payer_company_id', 'prohibits:payer_company_id', 'nullable', 'integer'],
            'payer_company_id' => [
                'required_without:payer_guest_id',
                'nullable',
                'integer',
                Rule::prohibitedIf(! $this->user()->can(PermissionEnum::CompanyView->value)),
                Rule::exists('companies', 'id')
                    ->where('property_id', $property instanceof Property ? $property->id : null)
                    ->where('status', CompanyStatus::Active->value),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payer_guest_id.required_without' => __('Choose who pays this folio.'),
            'payer_company_id.required_without' => __('Choose who pays this folio.'),
            'payer_company_id.exists' => __('This company is not available. Pick an active company.'),
        ];
    }
}
