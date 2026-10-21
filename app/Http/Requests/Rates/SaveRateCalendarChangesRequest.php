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
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Spatie\LaravelData\Optional;

class SaveRateCalendarChangesRequest extends FormRequest
{
    public const MAX_CHANGES = 200;

    /**
     * The route middleware authorizes this request: `can:rates.manage`.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * One rule set for each change, because a Day use plan refuses prices and stay limits.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Property $property */
        $property = $this->route('property');
        $changes = $this->input('changes');
        $indexes = is_array($changes) ? array_keys($changes) : [];
        $dayUsePlanIds = RatePlan::query()->where('property_id', $property->id)->where('pricing_basis', PricingBasis::DayUse)->pluck('id')->all();
        $maxAdults = RoomType::query()->where('property_id', $property->id)->pluck('max_adults', 'id');

        $rules = [
            'changes' => ['required', 'array', 'min:1', 'max:'.self::MAX_CHANGES],
        ];

        foreach ($indexes as $index) {
            $change = $changes[$index];
            $isDayUse = is_array($change) && in_array($change['rate_plan_id'] ?? null, $dayUsePlanIds);
            $roomTypeId = is_array($change) ? ($change['room_type_id'] ?? null) : null;
            $prefix = 'changes.'.$index;

            $rules += [
                $prefix.'.rate_plan_id' => ['required', 'integer', Rule::exists('rate_plans', 'id')->where('property_id', $property->id)],
                $prefix.'.room_type_id' => ['required', 'integer', Rule::exists('room_types', 'id')->where('property_id', $property->id)],
                $prefix.'.date' => ['required', 'date_format:Y-m-d'],
                $prefix.'.amount' => [$isDayUse ? 'prohibited' : 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
                $prefix.'.occupancy' => [$isDayUse ? 'prohibited' : 'nullable', 'array'],
                $prefix.'.occupancy.*.adults' => [
                    'required', 'integer', 'min:1',
                    function (string $attribute, mixed $value, Closure $fail) use ($maxAdults, $roomTypeId): void {
                        if ($value > ($maxAdults[$roomTypeId] ?? 0)) {
                            $fail(__('That is more adults than the room type sleeps.'));
                        }
                    },
                ],
                $prefix.'.occupancy.*.amount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
                $prefix.'.restrictions' => ['nullable', 'array'],
                $prefix.'.restrictions.min_stay' => [$isDayUse ? 'prohibited' : 'nullable', 'integer', 'min:1', 'max:365'],
                $prefix.'.restrictions.max_stay' => [
                    $isDayUse ? 'prohibited' : 'nullable', 'integer', 'min:1', 'max:365',
                    function (string $attribute, mixed $value, Closure $fail) use ($prefix): void {
                        $min = $this->input($prefix.'.restrictions.min_stay');

                        if ($value !== null && is_numeric($min) && $value < $min) {
                            $fail(__('The max stay cannot be below the min stay.'));
                        }
                    },
                ],
                $prefix.'.restrictions.closed_to_arrival' => [$isDayUse ? 'prohibited' : 'boolean'],
                $prefix.'.restrictions.closed_to_departure' => [$isDayUse ? 'prohibited' : 'boolean'],
                $prefix.'.restrictions.stop_sell' => ['boolean'],
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'changes.*.date' => __('date'),
            'changes.*.amount' => __('price'),
            'changes.*.occupancy.*.adults' => __('adult count'),
            'changes.*.occupancy.*.amount' => __('price for adults'),
            'changes.*.restrictions.min_stay' => __('min stay'),
            'changes.*.restrictions.max_stay' => __('max stay'),
        ];
    }

    /**
     * A plan, room type and date can be changed once in a request.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $seen = [];

            foreach ((array) $this->input('changes') as $index => $change) {
                if (! is_array($change)) {
                    continue;
                }

                $key = ($change['rate_plan_id'] ?? '').'|'.($change['room_type_id'] ?? '').'|'.($change['date'] ?? '');

                if (isset($seen[$key])) {
                    $validator->errors()->add('changes.'.$index.'.date', __('Each date can be changed once for a plan and room type.'));
                }

                $seen[$key] = true;
            }
        }];
    }

    /**
     * The changes with a blank price left out and only the restriction columns that were sent.
     *
     * @return list<array{rate_plan_id: int, room_type_id: int, date: string, amount: string|null, occupancy: list<array{adults: int, amount: string}>, restrictions: RateRestrictionsData}>
     */
    public function changes(): array
    {
        return array_map(function (array $change): array {
            $restrictions = $change['restrictions'] ?? [];
            $amount = isset($change['amount']) && $change['amount'] !== '' ? (string) $change['amount'] : null;

            return [
                'rate_plan_id' => (int) $change['rate_plan_id'],
                'room_type_id' => (int) $change['room_type_id'],
                'date' => (string) $change['date'],
                'amount' => $amount,
                'occupancy' => $amount === null ? [] : array_map(
                    fn (array $price): array => ['adults' => (int) $price['adults'], 'amount' => (string) $price['amount']],
                    array_values($change['occupancy'] ?? []),
                ),
                'restrictions' => new RateRestrictionsData(
                    minStay: array_key_exists('min_stay', $restrictions) ? $this->nullableInt($restrictions['min_stay']) : new Optional,
                    maxStay: array_key_exists('max_stay', $restrictions) ? $this->nullableInt($restrictions['max_stay']) : new Optional,
                    closedToArrival: array_key_exists('closed_to_arrival', $restrictions) ? (bool) $restrictions['closed_to_arrival'] : new Optional,
                    closedToDeparture: array_key_exists('closed_to_departure', $restrictions) ? (bool) $restrictions['closed_to_departure'] : new Optional,
                    stopSell: array_key_exists('stop_sell', $restrictions) ? (bool) $restrictions['stop_sell'] : new Optional,
                ),
            ];
        }, array_values($this->validated('changes')));
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
