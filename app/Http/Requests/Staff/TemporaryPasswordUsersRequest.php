<?php

namespace App\Http\Requests\Staff;

use App\Models\Property;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Staff chosen for a temp password reset, copy or export. Every id must belong to the property in the URL.
 */
class TemporaryPasswordUsersRequest extends FormRequest
{
    /**
     * The `can:staff.manage` route middleware authorizes this request.
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
        /** @var Property $property */
        $property = $this->route('property');

        return [
            'user_ids' => ['required', 'array', 'min:1', 'max:200'],
            'user_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->where('property_id', $property->id)],
        ];
    }
}
