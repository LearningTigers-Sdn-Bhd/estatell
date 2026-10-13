<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Partner users chosen for a temp password reset, copy or export. Every id must be a user that belongs to a partner.
 */
class PartnerTemporaryPasswordUsersRequest extends FormRequest
{
    /**
     * The `can:admin` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_ids' => ['required', 'array', 'min:1', 'max:200'],
            'user_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->whereNotNull('partner_id')],
        ];
    }
}
