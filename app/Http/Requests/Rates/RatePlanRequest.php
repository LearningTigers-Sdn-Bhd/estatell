<?php

namespace App\Http\Requests\Rates;

use App\Enums\PackageUnit;
use App\Enums\PricingBasis;
use App\Models\Property;
use App\Models\RatePlan;
use App\Services\Rates\DerivedRatePlans;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RatePlanRequest extends FormRequest
{
    /**
     * The `can:settings.manage` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'description' => filled($this->input('description')) ? trim((string) $this->input('description')) : null,
            'day_use_from' => filled($this->input('day_use_from')) ? $this->input('day_use_from') : null,
            'day_use_until' => filled($this->input('day_use_until')) ? $this->input('day_use_until') : null,
        ]);
    }

    /**
     * The extra guest charges depend on the plan's pricing basis: per room plans charge extra adults and children,
     * per guest plans charge a single supplement, and other plans charge neither. A package also has its number of
     * nights, and a package charged per room charges extra adults and children per stay.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Property $property */
        $property = $this->route('property');
        /** @var RatePlan $current */
        $current = $this->route('ratePlan');
        $amount = ['required', 'decimal:0,2', 'min:0', 'max:9999999999'];

        return [
            'name' => [
                'required', 'string', 'max:100',
                function (string $attribute, mixed $value, Closure $fail) use ($property, $current): void {
                    $taken = RatePlan::query()
                        ->where('property_id', $property->id)
                        ->active()
                        ->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])
                        ->whereKeyNot($current->id)
                        ->exists();

                    if ($taken) {
                        $fail(__('Another active rate plan already uses this name.'));
                    }
                },
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            ...match ($current->pricing_basis) {
                PricingBasis::PerRoom => ['extra_adult_amount' => $amount, 'extra_child_amount' => $amount],
                PricingBasis::PerGuest => ['single_supplement' => $amount],
                PricingBasis::PerBed => [],
                PricingBasis::DayUse => $this->dayUseRules(),
                PricingBasis::Package => [
                    'fixed_nights' => [
                        'required', 'integer', 'between:1,30',
                        function (string $attribute, mixed $value, Closure $fail) use ($current): void {
                            $locked = (int) $value === $current->fixed_nights ? null : app(DerivedRatePlans::class)->nightsLockMessage($current);

                            if ($locked !== null) {
                                $fail($locked);
                            }
                        },
                    ],
                    ...($current->package_unit === PackageUnit::PerRoom ? ['extra_adult_amount' => $amount, 'extra_child_amount' => $amount] : []),
                ],
            },
        ];
    }

    /**
     * Hours sold (whole hours, 1 to 24) and the optional Sold between window. The window needs both times, from before until,
     * and room for at least the minimum hours.
     *
     * @return array<string, array<mixed>>
     */
    private function dayUseRules(): array
    {
        $time = fn (string $other): array => ['nullable', 'date_format:H:i', 'required_with:'.$other];

        return [
            'day_use_min_hours' => ['required', 'integer', 'between:1,24'],
            'day_use_max_hours' => ['required', 'integer', 'between:1,24', 'gte:day_use_min_hours'],
            'day_use_from' => $time('day_use_until'),
            'day_use_until' => [
                ...$time('day_use_from'),
                'after:day_use_from',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $from = $this->input('day_use_from');
                    $min = (int) $this->input('day_use_min_hours');

                    if (is_string($from) && is_string($value) && $from !== '' && $value !== '' && $min > 0
                        && (strtotime('1970-01-01 '.$value) - strtotime('1970-01-01 '.$from)) < $min * 3600) {
                        $fail(__('The window must be at least :hours hours long, the minimum hours sold.', ['hours' => $min]));
                    }
                },
            ],
        ];
    }
}
