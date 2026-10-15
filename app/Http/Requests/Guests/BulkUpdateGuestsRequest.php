<?php

namespace App\Http\Requests\Guests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkUpdateGuestsRequest extends FormRequest
{
    public const int MAX_GUESTS = 100;

    /**
     * Determine if the user is authorized to make this request.
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
            'guest_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_GUESTS],
            'guest_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'reason' => [Rule::requiredIf($this->routeIs('guests.bulk.blacklist')), 'nullable', 'string', 'max:500'],
            'confirmation' => [Rule::requiredIf($this->routeIs('guests.bulk.anonymize')), 'nullable', 'string', Rule::in(['Confirm'])],
        ];
    }

    /**
     * @return list<int>
     */
    public function guestIds(): array
    {
        return array_values(array_map('intval', $this->validated('guest_ids')));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'guest_ids.max' => __('Select up to :max guests at a time.'),
            'confirmation.required' => __('Type Confirm to continue.'),
            'confirmation.in' => __('Type Confirm to continue.'),
        ];
    }
}
