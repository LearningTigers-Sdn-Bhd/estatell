<?php

namespace App\Http\Requests\Rates;

use App\Enums\PricingBasis;
use App\Models\RatePlan;
use App\Models\RoomType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Date;

class SaveRatesRequest extends FormRequest
{
    public const MAX_DAYS = 731;

    private const RESTRICTION_KEYS = ['min_stay', 'max_stay', 'closed_to_arrival', 'closed_to_departure', 'stop_sell'];

    /**
     * The route middleware authorizes this request: `can:settings.manage` on the plan editor and `can:rates.manage`
     * on the rate calendar.
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
        /** @var RatePlan $ratePlan */
        $ratePlan = $this->route('ratePlan');
        $linkedIds = $ratePlan->roomTypes()->whereNull('room_types.archived_at')->pluck('room_types.id')->all();
        $maxAdults = RoomType::query()->whereIn('id', $linkedIds)->pluck('max_adults', 'id');

        $isDayUse = $ratePlan->pricing_basis === PricingBasis::DayUse;

        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => [
                'required', 'date_format:Y-m-d', 'after_or_equal:from',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $from = $this->input('from');

                    if (is_string($from) && is_string($value) && Date::parse($from)->diffInDays(Date::parse($value)) >= self::MAX_DAYS) {
                        $fail(__('Pick at most :days days.', ['days' => self::MAX_DAYS]));
                    }
                },
            ],
            'room_types' => ['required', 'array', 'min:1'],
            'room_types.*.room_type_id' => [
                'required', 'integer', 'distinct',
                function (string $attribute, mixed $value, Closure $fail) use ($linkedIds): void {
                    if (! in_array($value, $linkedIds, true)) {
                        $fail(__('This rate plan does not sell that room type.'));
                    }
                },
            ],
            'room_types.*.days' => ['nullable', 'array', 'max:7'],
            'room_types.*.days.*.weekday' => [
                'required', 'integer', 'between:1,7',
                fn (string $attribute, mixed $value, Closure $fail) => $this->failWhenRepeated($attribute, $value, 'weekday', $fail, __('Each weekday can be sent once.')),
            ],
            'room_types.*.days.*.amount' => [$isDayUse ? 'prohibited' : 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'room_types.*.days.*.hours' => [$isDayUse ? 'nullable' : 'prohibited', 'array'],
            'room_types.*.days.*.hours.*.hours' => [
                'required', 'integer', 'between:'.($ratePlan->day_use_min_hours ?? 1).','.($ratePlan->day_use_max_hours ?? 24),
                fn (string $attribute, mixed $value, Closure $fail) => $this->failWhenRepeated($attribute, $value, 'hours', $fail, __('Each hour count can be set once.')),
            ],
            'room_types.*.days.*.hours.*.amount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'room_types.*.days.*.occupancy' => [$isDayUse ? 'prohibited' : 'nullable', 'array'],
            'room_types.*.days.*.occupancy.*.adults' => [
                'required', 'integer', 'min:1',
                function (string $attribute, mixed $value, Closure $fail) use ($maxAdults): void {
                    $this->failWhenRepeated($attribute, $value, 'adults', $fail, __('Each adult count can be set once.'));

                    $roomTypeId = $this->input('room_types.'.explode('.', $attribute)[1].'.room_type_id');

                    if ($value > ($maxAdults[$roomTypeId] ?? 0)) {
                        $fail(__('That is more adults than the room type sleeps.'));
                    }
                },
            ],
            'room_types.*.days.*.occupancy.*.amount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'restrictions' => ['nullable', 'array'],
            'restrictions.min_stay' => [$isDayUse ? 'prohibited' : 'nullable', 'integer', 'min:1', 'max:365'],
            'restrictions.max_stay' => [
                $isDayUse ? 'prohibited' : 'nullable', 'integer', 'min:1', 'max:365',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $min = $this->input('restrictions.min_stay');

                    if ($value !== null && is_numeric($min) && $value < $min) {
                        $fail(__('The max stay cannot be below the min stay.'));
                    }
                },
            ],
            'restrictions.closed_to_arrival' => [$isDayUse ? 'prohibited' : 'boolean'],
            'restrictions.closed_to_departure' => [$isDayUse ? 'prohibited' : 'boolean'],
            'restrictions.stop_sell' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'from' => __('start date'),
            'to' => __('end date'),
            'room_types.*.room_type_id' => __('room type'),
            'room_types.*.days.*.weekday' => __('weekday'),
            'room_types.*.days.*.amount' => __('price'),
            'room_types.*.days.*.hours.*.hours' => __('hour count'),
            'room_types.*.days.*.hours.*.amount' => __('price for hours'),
            'room_types.*.days.*.occupancy.*.adults' => __('adult count'),
            'room_types.*.days.*.occupancy.*.amount' => __('price for adults'),
            'restrictions.min_stay' => __('min stay'),
            'restrictions.max_stay' => __('max stay'),
        ];
    }

    /**
     * Fails when a value repeats within its own list. Laravel's `distinct` compares across every room type.
     */
    private function failWhenRepeated(string $attribute, mixed $value, string $key, Closure $fail, string $message): void
    {
        $segments = explode('.', $attribute);
        $siblings = $this->input(implode('.', array_slice($segments, 0, -2)));

        if (is_array($siblings) && count(array_filter($siblings, fn (mixed $sibling): bool => is_array($sibling) && ($sibling[$key] ?? null) == $value)) > 1) {
            $fail($message);
        }
    }

    /**
     * The room types with their days keyed by ISO weekday. A blank amount means "leave that weekday's price alone".
     *
     * @return list<array{room_type_id: int, days: array<int, array{amount: string|null, hours: list<array{hours: int, amount: string}>, occupancy: list<array{adults: int, amount: string}>}>}>
     */
    public function roomTypes(): array
    {
        return array_map(function (array $roomType): array {
            $days = [];

            foreach ($roomType['days'] ?? [] as $day) {
                $hours = array_map(
                    fn (array $price): array => ['hours' => (int) $price['hours'], 'amount' => (string) $price['amount']],
                    array_values($day['hours'] ?? []),
                );

                if ($hours !== []) {
                    $days[(int) $day['weekday']] = ['amount' => null, 'occupancy' => [], 'hours' => $hours];

                    continue;
                }

                if (! isset($day['amount']) || $day['amount'] === '') {
                    continue;
                }

                $days[(int) $day['weekday']] = [
                    'amount' => (string) $day['amount'],
                    'hours' => [],
                    'occupancy' => array_map(
                        fn (array $price): array => ['adults' => (int) $price['adults'], 'amount' => (string) $price['amount']],
                        array_values($day['occupancy'] ?? []),
                    ),
                ];
            }

            return ['room_type_id' => (int) $roomType['room_type_id'], 'days' => $days];
        }, array_values($this->validated('room_types')));
    }

    /**
     * Only the restriction columns the form sent.
     *
     * @return array{min_stay?: int|null, max_stay?: int|null, closed_to_arrival?: bool, closed_to_departure?: bool, stop_sell?: bool}
     */
    public function restrictions(): array
    {
        $restrictions = [];

        foreach (self::RESTRICTION_KEYS as $key) {
            if (! $this->has('restrictions.'.$key)) {
                continue;
            }

            $value = $this->input('restrictions.'.$key);
            $restrictions[$key] = in_array($key, ['min_stay', 'max_stay'], true)
                ? ($value === null || $value === '' ? null : (int) $value)
                : (bool) $value;
        }

        return $restrictions;
    }
}
