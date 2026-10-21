<?php

namespace App\Http\Requests\Rates;

use App\Data\Rates\RateRestrictionsData;
use App\Enums\PricingBasis;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RoomType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Optional;

class SaveBulkRatesRequest extends FormRequest
{
    public const MAX_DAYS = SaveRatesRequest::MAX_DAYS;

    public const MAX_PLANS = 50;

    /**
     * The route middleware authorizes this request: `can:rates.manage`.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Several plans over one date range. A Day use plan is edited alone, through `SaveRatesRequest`.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Property $property */
        $property = $this->route('property');
        $dayUseIds = RatePlan::query()->where('property_id', $property->id)->where('pricing_basis', PricingBasis::DayUse)->pluck('id')->all();
        $linked = DB::table('rate_plan_room_types')
            ->join('room_types', 'room_types.id', '=', 'rate_plan_room_types.room_type_id')
            ->whereNull('room_types.archived_at')
            ->where('room_types.property_id', $property->id)
            ->get(['rate_plan_room_types.rate_plan_id', 'rate_plan_room_types.room_type_id'])
            ->groupBy('rate_plan_id')
            ->map(fn ($rows): array => $rows->pluck('room_type_id')->all());
        $maxAdults = RoomType::query()->where('property_id', $property->id)->pluck('max_adults', 'id');

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
            'plans' => ['required', 'array', 'min:1', 'max:'.self::MAX_PLANS],
            'plans.*.rate_plan_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('rate_plans', 'id')->where('property_id', $property->id),
                function (string $attribute, mixed $value, Closure $fail) use ($dayUseIds): void {
                    if (in_array($value, $dayUseIds, true)) {
                        $fail(__('A Day use plan is edited on its own.'));
                    }
                },
            ],
            'plans.*.room_types' => ['required', 'array', 'min:1'],
            'plans.*.room_types.*.room_type_id' => [
                'required', 'integer',
                function (string $attribute, mixed $value, Closure $fail) use ($linked): void {
                    $planId = $this->input('plans.'.explode('.', $attribute)[1].'.rate_plan_id');

                    if (! in_array($value, $linked->get($planId, []), true)) {
                        $fail(__('This rate plan does not sell that room type.'));
                    }

                    $this->failWhenRepeated($attribute, $value, 'room_type_id', $fail, __('Each room type can be sent once for a plan.'), 2);
                },
            ],
            'plans.*.room_types.*.days' => ['nullable', 'array', 'max:7'],
            'plans.*.room_types.*.days.*.weekday' => [
                'required', 'integer', 'between:1,7',
                fn (string $attribute, mixed $value, Closure $fail) => $this->failWhenRepeated($attribute, $value, 'weekday', $fail, __('Each weekday can be sent once.')),
            ],
            'plans.*.room_types.*.days.*.amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'plans.*.room_types.*.days.*.occupancy' => ['nullable', 'array'],
            'plans.*.room_types.*.days.*.occupancy.*.adults' => [
                'required', 'integer', 'min:1',
                function (string $attribute, mixed $value, Closure $fail) use ($maxAdults): void {
                    $this->failWhenRepeated($attribute, $value, 'adults', $fail, __('Each adult count can be set once.'));

                    $segments = explode('.', $attribute);
                    $roomTypeId = $this->input('plans.'.$segments[1].'.room_types.'.$segments[3].'.room_type_id');

                    if ($value > ($maxAdults[$roomTypeId] ?? 0)) {
                        $fail(__('That is more adults than the room type sleeps.'));
                    }
                },
            ],
            'plans.*.room_types.*.days.*.occupancy.*.amount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'restrictions' => ['nullable', 'array'],
            'restrictions.min_stay' => ['nullable', 'integer', 'min:1', 'max:365'],
            'restrictions.max_stay' => [
                'nullable', 'integer', 'min:1', 'max:365',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $min = $this->input('restrictions.min_stay');

                    if ($value !== null && is_numeric($min) && $value < $min) {
                        $fail(__('The max stay cannot be below the min stay.'));
                    }
                },
            ],
            'restrictions.closed_to_arrival' => ['boolean'],
            'restrictions.closed_to_departure' => ['boolean'],
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
            'plans.*.rate_plan_id' => __('rate plan'),
            'plans.*.room_types.*.room_type_id' => __('room type'),
            'plans.*.room_types.*.days.*.weekday' => __('weekday'),
            'plans.*.room_types.*.days.*.amount' => __('price'),
            'plans.*.room_types.*.days.*.occupancy.*.adults' => __('adult count'),
            'plans.*.room_types.*.days.*.occupancy.*.amount' => __('price for adults'),
            'restrictions.min_stay' => __('min stay'),
            'restrictions.max_stay' => __('max stay'),
        ];
    }

    /**
     * Fails when a value repeats within its own list. Laravel's `distinct` compares across every list.
     *
     * @param  int  $levels  How many segments up the list is: 2 for `list.N.key`.
     */
    private function failWhenRepeated(string $attribute, mixed $value, string $key, Closure $fail, string $message, int $levels = 2): void
    {
        $segments = explode('.', $attribute);
        $siblings = $this->input(implode('.', array_slice($segments, 0, -$levels)));

        if (is_array($siblings) && count(array_filter($siblings, fn (mixed $sibling): bool => is_array($sibling) && ($sibling[$key] ?? null) == $value)) > 1) {
            $fail($message);
        }
    }

    /**
     * The plans with their room types and days keyed by ISO weekday. A blank amount means "leave that weekday's
     * price alone".
     *
     * @return list<array{rate_plan_id: int, room_types: list<array{room_type_id: int, days: array<int, array{amount: string|null, hours: list<never>, occupancy: list<array{adults: int, amount: string}>}>}>}>
     */
    public function plans(): array
    {
        return array_map(fn (array $plan): array => [
            'rate_plan_id' => (int) $plan['rate_plan_id'],
            'room_types' => array_map(function (array $roomType): array {
                $days = [];

                foreach ($roomType['days'] ?? [] as $day) {
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
            }, array_values($plan['room_types'])),
        ], array_values($this->validated('plans')));
    }

    /**
     * Only the restriction columns the form sent. A column that was not sent stays Optional, so it is left alone.
     */
    public function toRestrictionsData(): RateRestrictionsData
    {
        return new RateRestrictionsData(
            minStay: $this->has('restrictions.min_stay') ? $this->nullableInt('restrictions.min_stay') : new Optional,
            maxStay: $this->has('restrictions.max_stay') ? $this->nullableInt('restrictions.max_stay') : new Optional,
            closedToArrival: $this->has('restrictions.closed_to_arrival') ? (bool) $this->input('restrictions.closed_to_arrival') : new Optional,
            closedToDeparture: $this->has('restrictions.closed_to_departure') ? (bool) $this->input('restrictions.closed_to_departure') : new Optional,
            stopSell: $this->has('restrictions.stop_sell') ? (bool) $this->input('restrictions.stop_sell') : new Optional,
        );
    }

    private function nullableInt(string $key): ?int
    {
        $value = $this->input($key);

        return $value === null || $value === '' ? null : (int) $value;
    }
}
