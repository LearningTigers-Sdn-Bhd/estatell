<?php

namespace App\Http\Requests\Rates;

use App\Enums\PortalAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RatePlanPortalRequest extends FormRequest
{
    /**
     * The `can:settings.manage` route middleware authorizes this request.
     */
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
            'portal_access' => ['required', Rule::enum(PortalAccess::class)],
        ];
    }

    public function portalAccess(): PortalAccess
    {
        return PortalAccess::from($this->validated('portal_access'));
    }
}
