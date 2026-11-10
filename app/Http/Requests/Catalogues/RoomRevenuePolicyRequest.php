<?php

namespace App\Http\Requests\Catalogues;

use App\Enums\PolicyChargeMode;
use App\Enums\PolicyRateBasis;
use App\Enums\PolicyScope;
use App\Enums\PolicyTab;
use App\Enums\SystemTransactionCode;
use App\Models\Property;
use App\Models\RoomRevenuePolicy;
use App\Models\RoomRevenuePolicyStep;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use LogicException;

class RoomRevenuePolicyRequest extends FormRequest
{
    /**
     * The `can:settings.manage` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A tab with one allowed scope does not need the form to send it.
     */
    protected function prepareForValidation(): void
    {
        $allowed = PolicyScope::allowedFor($this->code());

        if (count($allowed) === 1) {
            $this->merge(['applies_to' => $allowed[0]->value]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isCancellation = $this->code() === SystemTransactionCode::CancellationRevenue;
        $fixed = 'exclude_unless:charge_mode,'.PolicyChargeMode::FixedAmount->value;
        $percentage = 'exclude_unless:charge_mode,'.PolicyChargeMode::Percentage->value;

        return [
            'charge_mode' => ['required', Rule::enum(PolicyChargeMode::class)],
            'amount' => $isCancellation ? ['exclude'] : [$fixed, 'required', 'decimal:0,2', 'numeric', 'min:0.01', 'max:99999999.99'],
            'percent' => $isCancellation ? ['exclude'] : [$percentage, 'required', 'decimal:0,2', 'numeric', 'min:0.01', 'max:100'],
            'percent_basis' => [$percentage, 'required', Rule::enum(PolicyRateBasis::class)],
            'applies_to' => ['required', Rule::enum(PolicyScope::class)->only(PolicyScope::allowedFor($this->code()))],
            'steps' => $isCancellation ? ['exclude_if:charge_mode,'.PolicyChargeMode::Free->value, 'required', 'array', 'min:1', 'max:20'] : ['exclude'],
            'steps.*.days_before' => ['required', 'integer', 'min:0', 'max:365', 'distinct'],
            'steps.*.amount' => [$fixed, 'required', 'decimal:0,2', 'numeric', 'min:0', 'max:99999999.99'],
            'steps.*.percent' => [$percentage, 'required', 'decimal:0,2', 'numeric', 'min:0', 'max:100'],
            'own_taxes' => ['required', 'boolean'],
            'tax_ids' => ['exclude_unless:own_taxes,true', 'present', 'array'],
            'tax_ids.*' => ['integer', 'distinct', Rule::exists('taxes', 'id')->where('property_id', $this->property()->id)],
            'text_en' => ['required', 'string', 'max:500'],
            'text_ms' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'steps.required' => __('Add at least one step.'),
            'steps.*.days_before.distinct' => __('Each step needs its own number of days.'),
            'steps.*.days_before.required' => __('Enter the days.'),
            'steps.*.amount.required' => __('Enter the amount.'),
            'steps.*.percent.required' => __('Enter the percent.'),
        ];
    }

    /**
     * The policy as typed, not saved, with its typed steps. Fields the charge mode does not use stay empty.
     */
    public function unsavedPolicy(): RoomRevenuePolicy
    {
        $policy = new RoomRevenuePolicy([...Arr::except($this->validated(), ['tax_ids', 'steps']), 'code' => $this->code()]);
        $policy->setRelation('steps', collect($this->steps())->map(fn (array $step): RoomRevenuePolicyStep => new RoomRevenuePolicyStep($step))->sortByDesc('days_before')->values());

        return $policy;
    }

    /**
     * The typed steps of a paid cancellation, with the charge field of the mode only. Empty for other policies.
     *
     * @return list<array{days_before: int, amount: string|null, percent: string|null}>
     */
    public function steps(): array
    {
        return array_values(array_map(fn (array $step): array => [
            'days_before' => (int) $step['days_before'],
            'amount' => isset($step['amount']) ? (string) $step['amount'] : null,
            'percent' => isset($step['percent']) ? (string) $step['percent'] : null,
        ], $this->validated('steps', [])));
    }

    /**
     * The tax ids typed for a policy with its own taxes, or null for a policy that follows the Taxes tab.
     *
     * @return list<int>|null
     */
    public function ownTaxIds(): ?array
    {
        return $this->boolean('own_taxes') ? array_map('intval', array_values($this->validated('tax_ids', []))) : null;
    }

    private function property(): Property
    {
        $property = $this->route('property');

        if (! $property instanceof Property) {
            throw new LogicException('The property route binding is missing.');
        }

        return $property;
    }

    public function code(): SystemTransactionCode
    {
        $tab = $this->route('tab');

        if (! $tab instanceof PolicyTab) {
            throw new LogicException('The policy tab route binding is missing.');
        }

        return $tab->code();
    }
}
