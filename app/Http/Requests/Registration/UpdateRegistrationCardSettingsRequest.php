<?php

namespace App\Http\Requests\Registration;

use App\Enums\RegistrationCardScope;
use App\Models\Property;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRegistrationCardSettingsRequest extends FormRequest
{
    /** The `can:settings.manage` route middleware authorizes this request. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['card_required_for_check_in' => $this->boolean('card_required_for_check_in')]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Property $property */
        $property = $this->route('property');

        return [
            'registration_card_scope' => ['required', Rule::enum(RegistrationCardScope::class)],
            'card_required_for_check_in' => ['required', 'boolean'],
            'id_document_retention_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'security_deposit_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'damage_charge_item_id' => ['nullable', 'integer', Rule::exists('charge_items', 'id')->where('property_id', $property->id)->where('active', true)],
        ];
    }
}
