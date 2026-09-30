<?php

namespace App\Http\Requests\Meals;

use App\Models\MealPeriod;
use App\Models\Property;
use App\Services\Meals\MealPeriodOverlap;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

class MealPeriodRequest extends FormRequest
{
    /** The `can:settings.manage` route middleware authorizes this request. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
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

        $mealPeriod = $this->route('mealPeriod');
        if (! $mealPeriod instanceof MealPeriod) {
            $mealPeriod = null;
        }

        return [
            'name' => [
                'required', 'string', 'max:100',
                function (string $attribute, mixed $value, Closure $fail) use ($property, $mealPeriod): void {
                    $taken = MealPeriod::query()
                        ->whereBelongsTo($property)
                        ->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])
                        ->when($mealPeriod instanceof MealPeriod, fn (Builder $query) => $query->whereKeyNot($mealPeriod->id))
                        ->exists();

                    if ($taken) {
                        $fail(__('Another meal period already uses this name.'));
                    }
                },
            ],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => [
                'bail', 'required', 'date_format:H:i', 'after:starts_at',
                function (string $attribute, mixed $value, Closure $fail) use ($property, $mealPeriod): void {
                    $startsAt = $this->input('starts_at');

                    if (
                        ! $this->boolean('active')
                        || ! is_string($startsAt)
                        || ! is_string($value)
                        || \DateTime::createFromFormat('H:i', $startsAt) === false
                    ) {
                        return;
                    }

                    if (app(MealPeriodOverlap::class)->exists($property, $startsAt, $value, $mealPeriod?->id)) {
                        $fail(__('These times overlap another active meal period.'));
                    }
                },
            ],
            'active' => ['required', 'boolean'],
        ];
    }
}
