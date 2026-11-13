<?php

namespace App\Http\Requests\Concierge;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreInfoTopicRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:100', 'regex:/\S/'],
            'body' => ['required', 'string', 'max:2000', 'regex:/\S/'],
        ];
    }
}
