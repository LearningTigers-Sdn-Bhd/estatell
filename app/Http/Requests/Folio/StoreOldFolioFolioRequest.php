<?php

namespace App\Http\Requests\Folio;

use App\Enums\CompanyStatus;
use App\Enums\PermissionEnum;
use App\Models\Property;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A folio added on the old folio page: a Master folio (no payer) or a third-party folio paid by a guest or an Active
 * company of the property. A company payer also needs `company.view`. The `can:folio.manual` route middleware
 * authorizes this request. The controller finds the guest inside the property.
 */
class StoreOldFolioFolioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $property = $this->route('property');

        return [
            'payer_guest_id' => ['nullable', 'prohibits:payer_company_id', 'integer'],
            'payer_company_id' => [
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
        return ['payer_company_id.exists' => __('This company is not available. Pick an active company.')];
    }
}
