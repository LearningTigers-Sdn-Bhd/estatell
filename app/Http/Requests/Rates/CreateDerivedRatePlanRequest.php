<?php

namespace App\Http\Requests\Rates;

use App\Enums\PermissionEnum;
use App\Enums\RateDerivation;
use App\Models\Property;
use App\Models\RatePlan;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateDerivedRatePlanRequest extends FormRequest
{
    /**
     * The `can:settings.manage` route middleware authorizes the request. Choosing companies also needs `company.manage`.
     */
    public function authorize(): bool
    {
        return $this->companyIds() === [] || $this->user()->can(PermissionEnum::CompanyManage->value);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
    }

    /**
     * The value is positive and `direction` gives its sign.
     *
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
            'derivation' => ['required', Rule::enum(RateDerivation::class)],
            'direction' => ['required', Rule::in(['down', 'up'])],
            'value' => [
                'required', 'decimal:0,2', 'min:0', 'max:9999999999',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->input('derivation') === RateDerivation::Percent->value && $this->input('direction') === 'down' && is_numeric($value) && $value >= 100) {
                        $fail(__('Enter a percentage below 100.'));
                    }
                },
            ],
            'room_type_ids' => ['required', 'array', 'min:1'],
            'room_type_ids.*' => ['integer', 'distinct'],
            'company_ids' => ['nullable', 'array', 'max:200'],
            'company_ids.*' => ['integer', 'distinct'],
        ];
    }

    public function derivation(): RateDerivation
    {
        return RateDerivation::from($this->validated('derivation'));
    }

    /**
     * The signed value: negative for Down, positive for Up.
     */
    public function signedValue(): string
    {
        $value = ltrim((string) $this->validated('value'), '-');

        return $this->validated('direction') === 'down' ? '-'.$value : $value;
    }

    /**
     * @return list<int>
     */
    public function roomTypeIds(): array
    {
        return array_values(array_unique(array_map(intval(...), $this->validated('room_type_ids') ?? [])));
    }

    /**
     * Read from the raw input, so `authorize()` can use it before validation runs.
     *
     * @return list<int>
     */
    public function companyIds(): array
    {
        return array_values(array_unique(array_map(intval(...), array_filter((array) $this->input('company_ids'), is_numeric(...)))));
    }
}
