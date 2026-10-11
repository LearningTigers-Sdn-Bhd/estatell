<?php

namespace App\Http\Requests\Reservations;

use App\Data\Reservations\NewUnitData;
use App\Enums\StayType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddUnitRequest extends FormRequest
{
    /**
     * The `can:reservations.manage` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the shape is checked here. `AddUnit` checks the stay, restrictions, availability and price, and refuses with a code.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'room_type_id' => ['required', 'integer'],
            'rate_plan_id' => ['required', 'integer'],
            'arrival_date' => ['required', 'date_format:Y-m-d'],
            'stay_type' => ['nullable', Rule::enum(StayType::class)],
            'departure_date' => ['required_unless:stay_type,day_use', 'nullable', 'date_format:Y-m-d'],
            'start_time' => ['required_if:stay_type,day_use', 'nullable', 'date_format:H:i'],
            'hours' => ['required_if:stay_type,day_use', 'nullable', 'integer', 'between:1,24'],
            'room_id' => ['required_if:stay_type,day_use', 'nullable', 'integer'],
            'adults' => ['required', 'integer', 'min:1', 'max:99'],
            'child_ages' => ['nullable', 'array', 'max:20'],
            'child_ages.*' => ['integer', 'between:0,17'],
            'override_total' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],
            'override_reason' => ['nullable', 'string', 'max:500'],
            'restriction_override_reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'override_total.regex' => __('Enter an amount such as 840.00.'),
        ];
    }

    public function toUnitData(): NewUnitData
    {
        $isDayUse = $this->input('stay_type') === StayType::DayUse->value;

        return new NewUnitData(
            roomTypeId: $this->integer('room_type_id'),
            ratePlanId: $this->integer('rate_plan_id'),
            arrivalDate: $this->date('arrival_date', 'Y-m-d')->toImmutable(),
            departureDate: $this->date($isDayUse ? 'arrival_date' : 'departure_date', 'Y-m-d')->toImmutable(),
            adults: $this->integer('adults'),
            childAges: array_map(intval(...), $this->validated('child_ages') ?? []),
            overrideTotal: $this->filled('override_total') ? $this->string('override_total')->toString() : null,
            overrideReason: $this->filled('override_reason') ? $this->string('override_reason')->toString() : null,
            restrictionOverrideReason: $this->filled('restriction_override_reason') ? $this->string('restriction_override_reason')->toString() : null,
            stayType: $isDayUse ? StayType::DayUse : StayType::Overnight,
            startTime: $isDayUse ? $this->string('start_time')->toString() : null,
            hours: $isDayUse ? $this->integer('hours') : null,
            roomId: $isDayUse ? $this->integer('room_id') : null,
        );
    }
}
