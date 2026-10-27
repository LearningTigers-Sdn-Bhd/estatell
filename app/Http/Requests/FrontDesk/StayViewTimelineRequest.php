<?php

namespace App\Http\Requests\FrontDesk;

use App\Data\StayView\StayViewFiltersData;
use App\Enums\HousekeepingStatus;
use App\Enums\StayViewGrouping;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StayViewTimelineRequest extends FormRequest
{
    /**
     * The longest window, counting both ends.
     */
    public const MAX_DAYS = 30;

    /**
     * The length of the window when the request gives none, counting both ends.
     */
    public const DEFAULT_DAYS = 14;

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
            'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d'],
            'to' => [
                'nullable', 'date_format:Y-m-d',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $from = $this->input('from');

                    if (! is_string($value) || ! is_string($from) || ! CarbonImmutable::hasFormat($from, 'Y-m-d')) {
                        return;
                    }

                    $days = CarbonImmutable::parse($from)->startOfDay()->diffInDays(CarbonImmutable::parse($value)->startOfDay(), false);

                    if ($days < 0) {
                        $fail(__('The end date must be on or after the start date.'));
                    } elseif ($days + 1 > self::MAX_DAYS) {
                        $fail(__('Pick at most :days days.', ['days' => self::MAX_DAYS]));
                    }
                },
            ],
            'groups' => ['nullable', 'array'],
            'groups.*' => ['integer'],
            'types' => ['nullable', 'array'],
            'types.*' => ['integer'],
            'statuses' => ['nullable', 'array'],
            'statuses.*' => [Rule::enum(HousekeepingStatus::class)],
            'q' => ['nullable', 'string', 'max:100'],
            'group_by' => ['nullable', Rule::enum(StayViewGrouping::class)],
        ];
    }

    /**
     * The first and last night to show. The first defaults to today and the last to a default-length window.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function window(CarbonImmutable $today): array
    {
        $from = $this->validated('from');
        $to = $this->validated('to');

        $first = $from === null ? $today : CarbonImmutable::createFromFormat('!Y-m-d', $from, $today->getTimezone());
        $last = $to === null
            ? $first->addDays(self::DEFAULT_DAYS - 1)
            : CarbonImmutable::createFromFormat('!Y-m-d', $to, $today->getTimezone());

        return [$first, $last];
    }

    public function filters(): StayViewFiltersData
    {
        return new StayViewFiltersData(
            roomGroupIds: array_values(array_map(intval(...), $this->validated('groups', []))),
            roomTypeIds: array_values(array_map(intval(...), $this->validated('types', []))),
            statuses: array_values($this->validated('statuses', [])),
            search: (string) $this->validated('q', ''),
            groupBy: StayViewGrouping::tryFrom((string) $this->validated('group_by', '')) ?? StayViewGrouping::RoomGroup,
        );
    }
}
