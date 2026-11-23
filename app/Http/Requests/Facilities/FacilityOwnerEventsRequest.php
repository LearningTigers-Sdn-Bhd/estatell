<?php

namespace App\Http\Requests\Facilities;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The date of the Timeline's Book facility For step, when it lists the events on that date.
 */
class FacilityOwnerEventsRequest extends FormRequest
{
    /**
     * The `can:events.view` route middleware authorizes this request.
     */
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
            'date' => ['required', 'date_format:Y-m-d'],
        ];
    }

    public function bookingDate(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->string('date')->toString());
    }
}
