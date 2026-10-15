<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add a channel or rename one. The route's `channel` parameter, when present, is left out of the unique check, so a
 * channel keeps its own name. The `can:admin` route middleware authorizes this request.
 */
class OtaChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:80',
                'regex:/[A-Za-z0-9]/',
                Rule::unique('ota_channels', 'name')->ignore($this->route('channel')),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => __('Use at least one letter or number.'),
            'name.unique' => __('A channel with this name already exists.'),
        ];
    }
}
