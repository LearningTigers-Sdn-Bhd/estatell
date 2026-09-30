<?php

namespace App\Http\Requests\Rates;

use App\Enums\Weekday;
use App\Models\RatePlan;
use App\Services\Packages\PackagePriceOverlap;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PackagePricingRequest extends FormRequest
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

        return [
            'prices' => ['present', 'array', 'max:200'],
            'prices.*.id' => ['nullable', 'integer', 'distinct'],
            'prices.*.room_type_id' => ['required', 'integer', Rule::in($ratePlan->roomTypes()->pluck('room_types.id')->all())],
            'prices.*.arrival_from' => ['required', 'date_format:Y-m-d'],
            'prices.*.arrival_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:prices.*.arrival_from'],
            'prices.*.amount' => ['required', 'decimal:0,2', 'numeric', 'min:0', 'max:9999999999.99'],
            'weekdays' => ['required', 'array', 'min:1'],
            'weekdays.*' => ['distinct', Rule::enum(Weekday::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'weekdays.required' => __('Pick at least one arrival day.'),
            'weekdays.min' => __('Pick at least one arrival day.'),
            'prices.*.room_type_id.in' => __('This plan does not sell that room type.'),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $overlap = app(PackagePriceOverlap::class)->firstOverlap($this->prices());

                if ($overlap !== null) {
                    $validator->errors()->add("prices.{$overlap}.arrival_from", __('This period overlaps another period of the same room type.'));
                }
            },
        ];
    }

    /**
     * @return list<array{id: int|null, room_type_id: int, arrival_from: string, arrival_to: string, amount: string}>
     */
    public function prices(): array
    {
        return array_map(
            fn (array $price): array => [
                'id' => isset($price['id']) ? (int) $price['id'] : null,
                'room_type_id' => (int) $price['room_type_id'],
                'arrival_from' => $price['arrival_from'],
                'arrival_to' => $price['arrival_to'],
                'amount' => (string) $price['amount'],
            ],
            array_values($this->validated('prices')),
        );
    }

    /**
     * @return list<string>
     */
    public function weekdays(): array
    {
        return array_values($this->validated('weekdays'));
    }
}
