<?php

namespace App\Http\Requests\Rates;

use App\Enums\PackageUnit;
use App\Enums\PricingBasis;
use App\Models\Property;
use App\Models\RatePlan;
use App\Services\Rates\RatePlanCoverage;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CreateRatePlanRequest extends FormRequest
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
        $this->merge(['name' => trim((string) $this->input('name'))]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Property $property */
        $property = $this->route('property');

        return [
            'name' => [
                'required', 'string', 'max:100',
                function (string $attribute, mixed $value, Closure $fail) use ($property): void {
                    $taken = RatePlan::query()
                        ->where('property_id', $property->id)
                        ->active()
                        ->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])
                        ->exists();

                    if ($taken) {
                        $fail(__('Another active rate plan already uses this name.'));
                    }
                },
            ],
            'pricing_basis' => ['required', Rule::enum(PricingBasis::class)],
            'fixed_nights' => ['exclude_unless:pricing_basis,package', 'required', 'integer', 'between:1,30'],
            'package_unit' => ['exclude_unless:pricing_basis,package', 'required', Rule::enum(PackageUnit::class)],
            'room_type_ids' => ['nullable', 'array'],
            'room_type_ids.*' => ['integer', 'distinct'],
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

                /** @var Property $property */
                $property = $this->route('property');
                $eligible = app(RatePlanCoverage::class)->sellableRoomTypeIds($property->id, PricingBasis::from($this->string('pricing_basis')->value()));

                if (array_diff($this->roomTypeIds(), $eligible) !== []) {
                    $validator->errors()->add('room_type_ids', __('This plan cannot sell one of the chosen room types.'));
                }
            },
        ];
    }

    /**
     * @return array{name: string, pricing_basis: string, fixed_nights?: int, package_unit?: string}
     */
    public function ratePlanAttributes(): array
    {
        return $this->safe()->only(['name', 'pricing_basis', 'fixed_nights', 'package_unit']);
    }

    /**
     * @return list<int>
     */
    public function roomTypeIds(): array
    {
        return array_values(array_unique(array_map('intval', $this->validated('room_type_ids') ?? [])));
    }
}
