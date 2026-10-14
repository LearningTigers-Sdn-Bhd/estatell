<?php

namespace App\Http\Requests\Rates;

use App\Enums\PricingBasis;
use App\Enums\RateDerivation;
use App\Models\RatePlan;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

class RatePlanDerivationRequest extends FormRequest
{
    /**
     * The `can:settings.manage` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A null parent means the plan sets its own prices. The value is positive and `direction` gives its sign.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'parent_rate_plan_id' => ['nullable', 'integer'],
            'derivation' => [
                'required_with:parent_rate_plan_id', 'nullable', Rule::enum(RateDerivation::class),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value !== null && $this->ratePlan()->pricing_basis === PricingBasis::Package && $value !== RateDerivation::Percent->value) {
                        $fail(__('A package can only follow by a percentage.'));
                    }
                },
            ],
            'direction' => ['required_with:parent_rate_plan_id', 'nullable', Rule::in(['down', 'up'])],
            'value' => [
                'required_with:parent_rate_plan_id', 'nullable', 'decimal:0,2', 'min:0', 'max:9999999999',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->input('derivation') === RateDerivation::Percent->value && $this->input('direction') === 'down' && is_numeric($value) && $value >= 100) {
                        $fail(__('Enter a percentage below 100.'));
                    }
                },
            ],
        ];
    }

    public function parentId(): ?int
    {
        $parentId = $this->validated('parent_rate_plan_id');

        return $parentId === null ? null : (int) $parentId;
    }

    public function derivation(): ?RateDerivation
    {
        $derivation = $this->validated('derivation');

        return $derivation === null ? null : RateDerivation::from($derivation);
    }

    /**
     * The signed value: negative for Down, positive for Up.
     */
    public function signedValue(): ?string
    {
        $value = $this->validated('value');

        if ($value === null || $this->validated('parent_rate_plan_id') === null) {
            return null;
        }

        return $this->validated('direction') === 'down' ? '-'.ltrim((string) $value, '-') : (string) $value;
    }

    private function ratePlan(): RatePlan
    {
        $ratePlan = $this->route('ratePlan');

        if (! $ratePlan instanceof RatePlan) {
            throw new LogicException('The rate plan route binding is missing.');
        }

        return $ratePlan;
    }
}
