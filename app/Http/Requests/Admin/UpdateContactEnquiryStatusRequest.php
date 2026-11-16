<?php

namespace App\Http\Requests\Admin;

use App\Enums\ContactEnquiryStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The new status of an enquiry. The `can:admin` route middleware authorizes this request.
 */
class UpdateContactEnquiryStatusRequest extends FormRequest
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
            'status' => ['required', Rule::enum(ContactEnquiryStatus::class)],
        ];
    }
}
