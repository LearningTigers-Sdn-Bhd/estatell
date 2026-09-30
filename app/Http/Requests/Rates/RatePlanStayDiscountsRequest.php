<?php

namespace App\Http\Requests\Rates;

use App\Enums\StayDiscountType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RatePlanStayDiscountsRequest extends FormRequest
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
        return [
            'discounts' => ['present', 'array', 'max:20'],
            'discounts.*.min_nights' => ['required', 'integer', 'between:2,365', 'distinct'],
            'discounts.*.discount_type' => ['required', Rule::enum(StayDiscountType::class)],
            'discounts.*.value' => [
                'required', 'decimal:0,2',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $index = explode('.', $attribute)[1];
                    $type = $this->input("discounts.{$index}.discount_type");

                    if ($type === StayDiscountType::Percent->value && (! is_numeric($value) || $value <= 0 || $value > 100)) {
                        $fail(__('Enter a percentage from 1 to 100.'));
                    }

                    if ($type === StayDiscountType::Amount->value && (! is_numeric($value) || $value <= 0 || $value > 9999999999)) {
                        $fail(__('Enter an amount above 0.'));
                    }
                },
            ],
            'discounts.*.from_night' => [
                'required', 'integer', 'min:1',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $index = explode('.', $attribute)[1];
                    $minNights = (int) $this->input("discounts.{$index}.min_nights");

                    if ((int) $value > $minNights) {
                        $fail(__('Start from a night no later than the minimum nights.'));
                    }
                },
            ],
        ];
    }

    /**
     * @return list<array{min_nights: int, discount_type: string, value: string, from_night: int}>
     */
    public function discounts(): array
    {
        return array_map(
            fn (array $discount): array => [
                'min_nights' => (int) $discount['min_nights'],
                'discount_type' => $discount['discount_type'],
                'value' => (string) $discount['value'],
                'from_night' => (int) $discount['from_night'],
            ],
            array_values($this->validated('discounts')),
        );
    }
}
