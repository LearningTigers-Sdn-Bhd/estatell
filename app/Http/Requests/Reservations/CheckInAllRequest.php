<?php

namespace App\Http\Requests\Reservations;

use App\Enums\EndedStayChoice;
use App\Enums\PermissionEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckInAllRequest extends FormRequest
{
    /**
     * The `can:front-desk.check-in` route middleware authorizes this request. Moving stays to today also changes their
     * dates, so it needs the permission of Change dates.
     */
    public function authorize(): bool
    {
        return $this->endedStayChoice() !== EndedStayChoice::ArrivingToday
            || $this->user()->can(PermissionEnum::ReservationsManage->value);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'unit_ids' => ['required', 'array', 'min:1'],
            'unit_ids.*' => ['integer'],
            'without_card' => ['boolean'],
            'ended_stay_choice' => ['nullable', Rule::enum(EndedStayChoice::class)],
            'arrived_at' => ['nullable', 'required_if:ended_stay_choice,'.EndedStayChoice::ArrivedLastNight->value, 'date_format:H:i'],
        ];
    }

    /**
     * @return list<int>
     */
    public function unitIds(): array
    {
        return array_values(array_map(intval(...), (array) $this->input('unit_ids')));
    }

    public function endedStayChoice(): ?EndedStayChoice
    {
        return EndedStayChoice::tryFrom((string) $this->input('ended_stay_choice'));
    }

    public function arrivedAt(): ?string
    {
        return filled($this->input('arrived_at')) ? $this->string('arrived_at')->toString() : null;
    }

    public function withoutCard(): bool
    {
        return $this->boolean('without_card') && $this->user()->can(PermissionEnum::FrontDeskCheckInWithoutCard->value);
    }
}
