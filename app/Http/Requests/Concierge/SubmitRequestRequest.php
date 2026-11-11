<?php

namespace App\Http\Requests\Concierge;

use App\Enums\GuestRequestType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A guest's request from their own page: a type and what they need.
 */
class SubmitRequestRequest extends FormRequest
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
            'type' => ['required', Rule::enum(GuestRequestType::class)->except(GuestRequestType::Refund)],
            'details' => ['required', 'string', 'max:1000', 'regex:/\S/'],
        ];
    }
}
