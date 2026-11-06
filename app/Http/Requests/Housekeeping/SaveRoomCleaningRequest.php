<?php

namespace App\Http\Requests\Housekeeping;

use App\Models\Property;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveRoomCleaningRequest extends FormRequest
{
    /**
     * `can:housekeeping.edit-times` on the route covers the request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['out_time' => filled($this->input('out_time')) ? $this->input('out_time') : null]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Property $property */
        $property = $this->route('property');

        return [
            'housekeeper_id' => ['required', 'integer', Rule::exists('users', 'id')->where('property_id', $property->id)->whereNull('deactivated_at')],
            'in_time' => ['required', 'date_format:H:i'],
            'out_time' => ['nullable', 'date_format:H:i', 'after_or_equal:in_time'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['out_time.after_or_equal' => __('OUT cannot be before IN.')];
    }

    public function housekeeper(): User
    {
        return User::query()->findOrFail($this->integer('housekeeper_id'));
    }
}
