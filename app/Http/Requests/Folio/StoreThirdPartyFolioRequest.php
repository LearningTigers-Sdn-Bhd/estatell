<?php

namespace App\Http\Requests\Folio;

use App\Enums\CompanyStatus;
use App\Enums\PermissionEnum;
use App\Models\Company;
use App\Models\Guest;
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
     * The guest payer, found inside the property. A merged or anonymized guest, or one of another property, is a 404.
     */
    public function payerGuest(Property $property): ?Guest
    {
        return $this->filled('payer_guest_id')
            ? Guest::query()->where('property_id', $property->id)->notMerged()->whereNull('anonymized_at')->findOrFail($this->integer('payer_guest_id'))
            : null;
    }

    /**
     * The company payer, found inside the property. The rules already require it to be Active.
     */
    public function payerCompany(Property $property): ?Company
    {
        return $this->filled('payer_company_id')
            ? Company::query()->where('property_id', $property->id)->findOrFail($this->integer('payer_company_id'))
            : null;
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
