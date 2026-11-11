<?php

namespace App\Http\Requests\Admin;

use App\Enums\LockProvider;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePropertyFeatureRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'active' => ['required', 'boolean'],
            // Door locks only: which lock providers the property uses. Left out means both.
            'providers' => ['nullable', 'array', 'min:1'],
            'providers.*' => ['string', Rule::enum(LockProvider::class), 'distinct'],
        ];
    }
}
