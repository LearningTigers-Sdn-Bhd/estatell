<?php

namespace App\Http\Requests\Onboarding;

use App\Enums\OnboardingStep;
use App\Enums\PricingBasis;
use App\Enums\SellUnit;
use App\Enums\WeekendNights;
use App\Models\Property;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;

/**
 * The Basic rate table of onboarding. There is one row for each active room type sold by room, no more and no fewer.
 * Prices are more than 0 with at most 2 decimals. The weekend price is needed unless "Same every night" is on.
 */
class SaveBasicRateRequest extends OnboardingStepRequest
{
    protected function step(): OnboardingStep
    {
        return OnboardingStep::BasicRate;
    }

    /**
     * The `onboarding.only` middleware limits who can reach this route.
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
        $property = $this->route('property');

        if (! $property instanceof Property) {
            throw new LogicException('The property route binding is missing.');
        }

        $price = ['numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99'];

        return [
            'weekend_nights' => ['required', Rule::enum(WeekendNights::class)],
            'same_every_night' => ['required', 'boolean'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.room_type_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('room_types', 'id')
                    ->where('property_id', $property->id)
                    ->where('sell_unit', SellUnit::Room->value)
                    ->whereNull('archived_at'),
            ],
            'rows.*.price_by' => ['required', Rule::enum(PricingBasis::class)->only([PricingBasis::PerRoom, PricingBasis::PerGuest])],
            'rows.*.weekday' => ['required', ...$price],
            'rows.*.weekend' => [Rule::requiredIf(! $this->boolean('same_every_night')), 'nullable', ...$price],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'rows.*.weekday' => __('weekday price'),
            'rows.*.weekend' => __('weekend price'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $afterValidator): void {
            $property = $this->route('property');

            if (! $property instanceof Property || $afterValidator->errors()->isNotEmpty()) {
                return;
            }

            $roomTypeIds = $property->roomTypes()->active()->where('sell_unit', SellUnit::Room)->pluck('id')->all();

            if (array_diff($roomTypeIds, array_column($this->validated('rows'), 'room_type_id')) !== []) {
                $afterValidator->errors()->add('rows', __('Price every room type.'));
            }
        });
    }

    public function weekendNights(): WeekendNights
    {
        return WeekendNights::from($this->validated('weekend_nights'));
    }

    public function sameEveryNight(): bool
    {
        return (bool) $this->validated('same_every_night');
    }

    /**
     * @return list<array{room_type_id: int, price_by: PricingBasis, weekday: string, weekend: string|null}>
     */
    public function rows(): array
    {
        return array_map(fn (array $row): array => [
            'room_type_id' => (int) $row['room_type_id'],
            'price_by' => PricingBasis::from($row['price_by']),
            'weekday' => number_format((float) $row['weekday'], 2, '.', ''),
            'weekend' => isset($row['weekend']) ? number_format((float) $row['weekend'], 2, '.', '') : null,
        ], array_values($this->validated('rows')));
    }
}
