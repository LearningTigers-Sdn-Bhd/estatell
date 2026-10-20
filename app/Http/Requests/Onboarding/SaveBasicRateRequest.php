<?php

namespace App\Http\Requests\Onboarding;

use App\Enums\OnboardingStep;
use App\Enums\PricingBasis;
use App\Enums\RateDerivation;
use App\Enums\SellUnit;
use App\Enums\WeekendNights;
use App\Models\Property;
use App\Models\RatePlan;
use App\Services\Onboarding\BasicRatePlans;
use App\Services\Onboarding\MoreRatePlans;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Validator;
use LogicException;

/**
 * The Basic rate table of onboarding. There is one row for each active room type sold by room, no more and no fewer.
 * Prices are more than 0 with at most 2 decimals. The weekend price is needed unless "Same every night" is on.
 */
class SaveBasicRateRequest extends OnboardingStepRequest
{
    public const MAX_MORE_RATES = 20;

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

    protected function prepareForValidation(): void
    {
        $moreRates = $this->input('more_rates');

        if (! is_array($moreRates)) {
            return;
        }

        $this->merge(['more_rates' => array_map(
            fn (mixed $row): mixed => is_array($row) ? [...$row, 'name' => trim((string) ($row['name'] ?? ''))] : $row,
            array_values($moreRates),
        )]);
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
            'more_rates' => ['nullable', 'array', 'max:'.self::MAX_MORE_RATES],
            'more_rates.*.key' => ['nullable', 'string', 'max:50'],
            'more_rates.*.plan_ids' => ['nullable', 'array'],
            'more_rates.*.plan_ids.*' => [
                'integer', 'distinct',
                Rule::exists('rate_plans', 'id')->where('property_id', $property->id)->whereNull('archived_at'),
            ],
            'more_rates.*.name' => ['required', 'string', 'max:100'],
            'more_rates.*.mode' => ['required', Rule::in(['follows', 'own'])],
            'more_rates.*.parent_basis' => ['required_if:more_rates.*.mode,follows', 'nullable', Rule::enum(PricingBasis::class)->only([PricingBasis::PerRoom, PricingBasis::PerGuest])],
            'more_rates.*.direction' => ['required_if:more_rates.*.mode,follows', 'nullable', Rule::in(['up', 'down'])],
            'more_rates.*.unit' => ['required_if:more_rates.*.mode,follows', 'nullable', Rule::in(['amount', 'percent'])],
            'more_rates.*.amount' => ['required_if:more_rates.*.mode,follows', 'nullable', ...$price],
            'more_rates.*.room_type_ids' => ['required_if:more_rates.*.mode,follows', 'nullable', 'array'],
            'more_rates.*.room_type_ids.*' => ['integer', $this->roomTypeExists($property)],
            'more_rates.*.prices' => ['required_if:more_rates.*.mode,own', 'nullable', 'array'],
            'more_rates.*.prices.*.room_type_id' => ['required', 'integer', $this->roomTypeExists($property)],
            'more_rates.*.prices.*.price_by' => ['required', Rule::enum(PricingBasis::class)->only([PricingBasis::PerRoom, PricingBasis::PerGuest])],
            'more_rates.*.prices.*.weekday' => ['required', ...$price],
            'more_rates.*.prices.*.weekend' => [Rule::requiredIf(! $this->boolean('same_every_night')), 'nullable', ...$price],
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
            'more_rates.*.name' => __('name'),
            'more_rates.*.amount' => __('amount'),
            'more_rates.*.prices.*.weekday' => __('weekday price'),
            'more_rates.*.prices.*.weekend' => __('weekend price'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'more_rates.*.room_type_ids.required_if' => __('Pick at least one room type'),
            'more_rates.*.prices.required_if' => __('Pick at least one room type'),
        ];
    }

    private function roomTypeExists(Property $property): Exists
    {
        return Rule::exists('room_types', 'id')
            ->where('property_id', $property->id)
            ->where('sell_unit', SellUnit::Room->value)
            ->whereNull('archived_at');
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

                return;
            }

            $this->checkMoreRates($afterValidator, $property);
        });
    }

    /**
     * A follower needs room types priced on its Basic Rate plan's basis. Its saved plans must be More rates, used by one
     * row. A name cannot be a Basic Rate name, repeat in the table or belong to another active plan.
     */
    private function checkMoreRates(Validator $validator, Property $property): void
    {
        $moreRatePlans = app(MoreRatePlans::class);
        $rows = $this->validated('rows');
        $bases = array_unique(array_column($rows, 'price_by'));
        $saved = $moreRatePlans->forProperty($property)->pluck('id')->all();
        $used = [];
        $names = [];

        foreach (array_values((array) $this->validated('more_rates', [])) as $index => $row) {
            $planIds = array_map(intval(...), (array) ($row['plan_ids'] ?? []));

            if (array_diff($planIds, $saved) !== [] || array_intersect($planIds, $used) !== []) {
                $validator->errors()->add("more_rates.{$index}.name", __('Reload the page and try again.'));
            }

            $used = [...$used, ...$planIds];

            if ($row['mode'] === 'own' && count(array_unique(array_column($row['prices'], 'room_type_id'))) !== count($row['prices'])) {
                $validator->errors()->add("more_rates.{$index}.prices", __('Each room type can appear once.'));
            }

            if ($row['mode'] === 'follows') {
                $basis = PricingBasis::from($row['parent_basis']);
                $allowed = array_column(array_filter($rows, fn (array $price): bool => $price['price_by'] === $basis->value), 'room_type_id');
                $label = $basis === PricingBasis::PerGuest ? __('guest') : __('room');

                if (! in_array($basis->value, $bases, true)) {
                    $validator->errors()->add("more_rates.{$index}.parent_basis", __('No room type is priced per :basis.', ['basis' => $label]));
                } elseif (array_diff(array_map(intval(...), $row['room_type_ids']), array_map(intval(...), $allowed)) !== []) {
                    $validator->errors()->add("more_rates.{$index}.room_type_ids", __('Pick only room types priced per :basis.', ['basis' => $label]));
                }

                if ($row['unit'] === 'percent' && $row['direction'] === 'down' && (float) $row['amount'] >= 100) {
                    $validator->errors()->add("more_rates.{$index}.amount", __('Enter a percentage below 100.'));
                }

                $resulting = [$row['name']];
            } else {
                $rowBases = array_values(array_unique(array_column($row['prices'], 'price_by')));
                $resulting = array_map(
                    fn (string $basis): string => $moreRatePlans->nameFor($row['name'], PricingBasis::from($basis), array_map(PricingBasis::from(...), $rowBases)),
                    $rowBases,
                );
            }

            foreach ($resulting as $name) {
                $lower = mb_strtolower($name);
                $reserved = array_map(mb_strtolower(...), [BasicRatePlans::NAME, BasicRatePlans::ROOM_NAME, BasicRatePlans::GUEST_NAME]);
                $taken = RatePlan::query()
                    ->where('property_id', $property->id)
                    ->active()
                    ->whereRaw('lower(name) = ?', [$lower])
                    ->whereNotIn('id', $planIds)
                    ->exists();

                if (in_array($lower, $reserved, true) || in_array($lower, $names, true) || $taken) {
                    $validator->errors()->add("more_rates.{$index}.name", __('Another rate already uses this name.'));
                }

                $names[] = $lower;
            }
        }
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

    /**
     * The More rates rows: saved plan ids, the name, the kind, and for a follower its Basic Rate basis, signed change and
     * room types, or for own prices the room type rows.
     *
     * @return list<array{
     *     key: string|null,
     *     plan_ids: list<int>,
     *     name: string,
     *     mode: string,
     *     parent_basis: PricingBasis|null,
     *     derivation: RateDerivation|null,
     *     signed_value: string|null,
     *     room_type_ids: list<int>,
     *     prices: list<array{room_type_id: int, price_by: PricingBasis, weekday: string, weekend: string|null}>,
     * }>
     */
    public function moreRates(): array
    {
        return array_map(function (array $row): array {
            $follows = $row['mode'] === 'follows';
            $amount = $follows ? number_format((float) $row['amount'], 2, '.', '') : null;

            return [
                'key' => $row['key'] ?? null,
                'plan_ids' => array_values(array_map(intval(...), $row['plan_ids'] ?? [])),
                'name' => $row['name'],
                'mode' => $row['mode'],
                'parent_basis' => $follows ? PricingBasis::from($row['parent_basis']) : null,
                'derivation' => $follows ? ($row['unit'] === 'percent' ? RateDerivation::Percent : RateDerivation::Amount) : null,
                'signed_value' => $follows ? ($row['direction'] === 'down' ? '-'.$amount : $amount) : null,
                'room_type_ids' => $follows ? array_values(array_unique(array_map(intval(...), $row['room_type_ids']))) : [],
                'prices' => $follows ? [] : array_map(fn (array $price): array => [
                    'room_type_id' => (int) $price['room_type_id'],
                    'price_by' => PricingBasis::from($price['price_by']),
                    'weekday' => number_format((float) $price['weekday'], 2, '.', ''),
                    'weekend' => isset($price['weekend']) ? number_format((float) $price['weekend'], 2, '.', '') : null,
                ], array_values($row['prices'])),
            ];
        }, array_values((array) $this->validated('more_rates', [])));
    }
}
