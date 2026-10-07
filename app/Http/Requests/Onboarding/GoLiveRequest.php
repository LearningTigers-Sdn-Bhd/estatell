<?php

namespace App\Http\Requests\Onboarding;

use App\Models\Property;
use App\Services\Property\PropertyToday;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class GoLiveRequest extends FormRequest
{
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
        /** @var Property $property */
        $property = $this->route('property');
        $today = app(PropertyToday::class)->date($property)->toDateString();

        return [
            'opening_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
        ];
    }
}
