<?php

namespace App\Http\Requests\Rates;

use App\Enums\AgeBandPricing;
use App\Models\Property;
use App\Models\RatePlan;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RatePlanChildPricesRequest extends FormRequest
{
    /**
     * The `can:settings.manage` route middleware authorizes this request.
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
        $groupIds = Property::query()->findOrFail($ratePlan->property_id)->ageGroups()->pluck('id')->all();

        return [
            'prices' => [
                'present', 'array',
                function (string $attribute, mixed $value, Closure $fail) use ($groupIds): void {
                    $sent = is_array($value) ? array_column($value, 'age_group_id') : [];

                    if (count($sent) !== count($groupIds) || array_diff($sent, $groupIds) !== []) {
                        $fail(__('The age groups changed. Reload the page and try again.'));
                    }
                },
            ],
            'prices.*.age_group_id' => ['required', 'integer', 'distinct'],
            'prices.*.pricing' => ['required', Rule::enum(AgeBandPricing::class)],
            'prices.*.value' => [
                'required_if:prices.*.pricing,percent,fixed', 'nullable', 'decimal:0,2',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $index = explode('.', $attribute)[1];
                    $pricing = $this->input("prices.{$index}.pricing");

                    if ($pricing === AgeBandPricing::Percent->value && (! is_numeric($value) || $value <= 0 || $value > 100)) {
                        $fail(__('Enter a percentage from 1 to 100.'));
                    }

                    if ($pricing === AgeBandPricing::Fixed->value && (! is_numeric($value) || $value < 0 || $value > 9999999999)) {
                        $fail(__('Enter an amount of 0 or more.'));
                    }
                },
            ],
        ];
    }

    /**
     * @return list<array{property_age_group_id: int, pricing: string, value: string|null}>
     */
    public function prices(): array
    {
        return array_map(
            fn (array $price): array => [
                'property_age_group_id' => (int) $price['age_group_id'],
                'pricing' => $price['pricing'],
                'value' => $price['value'] ?? null,
            ],
            array_values($this->validated('prices')),
        );
    }
}
