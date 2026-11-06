<?php

namespace App\Http\Requests\Kiosk;

use App\Data\Kiosk\KioskWalkInData;
use App\Http\Responses\ApiError;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class WalkInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->exists('child_ages')) {
            $this->merge(['child_ages' => []]);
        }
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'arrival' => ['required', 'date_format:Y-m-d'],
            'nights' => ['required', 'integer', 'min:1', 'max:30'],
            'adults' => ['required', 'integer', 'min:1', 'max:20'],
            'children' => ['required', 'integer', 'min:0', 'max:20'],
            'child_ages' => ['present', 'array', 'max:20', 'size:'.$this->integer('children')],
            'child_ages.*' => ['required', 'integer', 'min:0', 'max:17'],
            'room_type_id' => [Rule::requiredIf($this->routeIs('api.kiosk.v1.walk-in.book')), 'integer', 'min:1'],
        ];
    }

    public function search(): KioskWalkInData
    {
        return new KioskWalkInData(CarbonImmutable::parse($this->string('arrival')->toString()), $this->integer('nights'), $this->integer('adults'),
            array_values(array_map(fn (mixed $age): int => (int) $age, $this->input('child_ages', []))));
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(ApiError::response('no_room_available', __('Please check the stay details or see the front desk.'), 422));
    }
}
