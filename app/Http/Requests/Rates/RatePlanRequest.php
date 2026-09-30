<?php

namespace App\Http\Requests\Rates;

use App\Enums\PricingBasis;
use App\Models\Property;
use App\Models\RatePlan;
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
        ]);
    }

    /**
     * The extra guest charges depend on the plan's pricing basis: per room plans charge extra adults and children,
     * per guest plans charge a single supplement, and other plans charge neither.
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
                PricingBasis::PerBed, PricingBasis::Package => [],
            },
        ];
    }
}
