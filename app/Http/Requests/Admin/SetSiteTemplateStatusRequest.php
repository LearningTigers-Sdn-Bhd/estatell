<?php

namespace App\Http\Requests\Admin;

use App\Enums\SiteTemplateStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The `can:admin` route middleware authorizes this request.
 */
class SetSiteTemplateStatusRequest extends FormRequest
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
        return [
            'status' => ['required', Rule::enum(SiteTemplateStatus::class)],
        ];
    }

    public function status(): SiteTemplateStatus
    {
        return SiteTemplateStatus::from($this->string('status')->toString());
    }
}
