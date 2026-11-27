<?php

namespace App\Http\Requests\Property;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** E-invoicing › General: the three switches. */
class UpdateEInvoiceGeneralRequest extends FormRequest
{
    /** The `can:settings.manage` route middleware authorizes this request. */
    public function authorize(): bool
    {
        return true;
    }

    /** A switch that is off sends nothing, so a missing switch means off. */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'enabled' => $this->boolean('enabled'),
            'auto_submit_on_checkout' => $this->boolean('auto_submit_on_checkout'),
            'consolidation_enabled' => $this->boolean('consolidation_enabled'),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'auto_submit_on_checkout' => ['required', 'boolean'],
            'consolidation_enabled' => ['required', 'boolean'],
        ];
    }
}
