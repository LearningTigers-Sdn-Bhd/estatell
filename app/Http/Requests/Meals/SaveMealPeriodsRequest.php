<?php

namespace App\Http\Requests\Meals;

use App\Models\MealPeriod;
use App\Models\Property;
use App\Services\Meals\MealPeriodOverlap;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use LogicException;

class SaveMealPeriodsRequest extends FormRequest
{
    /** The most meal periods a property may have. */
    public const MAX_PERIODS = 12;

    /** The `can:settings.manage` route middleware authorizes this request. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $periods = $this->input('meal_periods');

        if (! is_array($periods)) {
            return;
        }

        $this->merge(['meal_periods' => array_map(
            fn (mixed $period): mixed => is_array($period) && isset($period['name']) && is_string($period['name'])
                ? [...$period, 'name' => trim($period['name'])]
                : $period,
            $periods,
        )]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $property = $this->route('property');
        if (! $property instanceof Property) {
            throw new LogicException('The property route binding is missing.');
        }

        $periods = $this->input('meal_periods');
        $periods = is_array($periods) ? array_values($periods) : [];

        return [
            'meal_periods' => ['present', 'array', 'max:'.self::MAX_PERIODS],
            'meal_periods.*.id' => [
                'nullable', 'integer', 'distinct',
                function (string $attribute, mixed $value, Closure $fail) use ($property): void {
                    if ($value !== null && ! MealPeriod::query()->whereBelongsTo($property)->whereKey($value)->exists()) {
                        $fail(__('This meal period no longer exists.'));
                    }
                },
            ],
            'meal_periods.*.name' => [
                'required', 'string', 'max:100',
                function (string $attribute, mixed $value, Closure $fail) use ($periods): void {
                    $same = collect($periods)
                        ->filter(fn (mixed $period): bool => is_array($period) && is_string($period['name'] ?? null) && mb_strtolower($period['name']) === mb_strtolower((string) $value))
                        ->count();

                    if ($same > 1) {
                        $fail(__('Another meal period already uses this name.'));
                    }
                },
            ],
            'meal_periods.*.starts_at' => ['required', 'date_format:H:i'],
            'meal_periods.*.ends_at' => ['bail', 'required', 'date_format:H:i', 'after:meal_periods.*.starts_at'],
            'meal_periods.*.active' => ['required', 'boolean'],
        ];
    }

    /**
     * Overlaps are checked once every row has valid times.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                foreach (app(MealPeriodOverlap::class)->overlappingPositions($this->periods()) as $position) {
                    $validator->errors()->add("meal_periods.{$position}.ends_at", __('These times overlap another active meal period.'));
                }
            },
        ];
    }

    /**
     * @return list<array{id: int|null, name: string, starts_at: string, ends_at: string, active: bool}>
     */
    public function periods(): array
    {
        return array_map(
            fn (array $period): array => [
                'id' => isset($period['id']) ? (int) $period['id'] : null,
                'name' => $period['name'],
                'starts_at' => $period['starts_at'],
                'ends_at' => $period['ends_at'],
                'active' => filter_var($period['active'], FILTER_VALIDATE_BOOLEAN),
            ],
            array_values($this->input('meal_periods')),
        );
    }
}
