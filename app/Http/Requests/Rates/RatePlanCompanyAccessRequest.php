<?php

namespace App\Http\Requests\Rates;

use App\Enums\CompanyAccess;
use App\Enums\CompanyRateMarket;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RatePlanCompanyAccessRequest extends FormRequest
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
            'company_access' => ['required', Rule::enum(CompanyAccess::class)],
            'company_at_desk' => ['sometimes', 'boolean'],
            'company_in_portal' => ['sometimes', 'boolean'],
            'company_market' => ['sometimes', Rule::enum(CompanyRateMarket::class)],
        ];
    }

    public function companyAccess(): CompanyAccess
    {
        return CompanyAccess::from($this->validated('company_access'));
    }

    /**
     * The market sent with the form, or null to keep the plan's current one.
     */
    public function companyMarket(): ?CompanyRateMarket
    {
        return $this->has('company_market') ? CompanyRateMarket::from($this->validated('company_market')) : null;
    }

    public function atDesk(): bool
    {
        return $this->boolean('company_at_desk', true);
    }

    public function inPortal(): bool
    {
        return $this->boolean('company_in_portal', true);
    }
}
