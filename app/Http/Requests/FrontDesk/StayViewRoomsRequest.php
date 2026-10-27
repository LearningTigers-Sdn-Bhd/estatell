<?php

namespace App\Http\Requests\FrontDesk;

use App\Data\StayView\StayViewFiltersData;
use App\Enums\HousekeepingStatus;
use App\Enums\RoomDayState;
use App\Enums\StayViewGrouping;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StayViewRoomsRequest extends FormRequest
{
    /**
     * The `can:front-desk.view` route middleware authorizes this request.
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
            'date' => ['nullable', 'string'],
            'groups' => ['nullable', 'array'],
            'groups.*' => ['integer'],
            'types' => ['nullable', 'array'],
            'types.*' => ['integer'],
            'statuses' => ['nullable', 'array'],
            'statuses.*' => [Rule::enum(HousekeepingStatus::class)],
            'show' => ['nullable', 'array'],
            'show.*' => [Rule::enum(RoomDayState::class)],
            'q' => ['nullable', 'string', 'max:100'],
            'group_by' => ['nullable', Rule::enum(StayViewGrouping::class)],
        ];
    }

    /**
     * The date to show. A missing or malformed `date` falls back to the given default, the business date.
     */
    public function onDate(CarbonImmutable $default): CarbonImmutable
    {
        $date = $this->validated('date');

        if (! is_string($date) || ! CarbonImmutable::hasFormat($date, 'Y-m-d')) {
            return $default;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $date, $default->getTimezone()) ?: $default;
    }

    public function filters(): StayViewFiltersData
    {
        return new StayViewFiltersData(
            roomGroupIds: array_values(array_map(intval(...), $this->validated('groups', []))),
            roomTypeIds: array_values(array_map(intval(...), $this->validated('types', []))),
            statuses: array_values($this->validated('statuses', [])),
            search: (string) $this->validated('q', ''),
            groupBy: StayViewGrouping::tryFrom((string) $this->validated('group_by', '')) ?? StayViewGrouping::RoomGroup,
            show: array_values($this->validated('show', [])),
        );
    }
}
