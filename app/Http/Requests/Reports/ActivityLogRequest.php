<?php

namespace App\Http\Requests\Reports;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ActivityLogRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array:search'],
            'filter.search' => ['nullable', 'string', 'max:200'],
            'sort' => ['sometimes', 'string', 'in:created_at,-created_at'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
