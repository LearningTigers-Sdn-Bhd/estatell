<?php

namespace App\Http\Requests\Admin;

use App\Models\Property;
use App\Services\Property\PropertyToday;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ApproveGoLiveRequest extends FormRequest
{
    /**
     * The opening date is optional (today in the property time zone) and never later than today.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Property $property */
        $property = $this->route('property');

        return [
            'opening_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.app(PropertyToday::class)->date($property)->toDateString()],
        ];
    }

    public function openingDate(): ?CarbonImmutable
    {
        $date = $this->validated('opening_date');

        return $date === null ? null : CarbonImmutable::parse($date)->startOfDay();
    }
}
