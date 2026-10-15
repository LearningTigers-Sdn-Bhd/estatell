<?php

namespace App\Http\Requests\Onboarding;

use App\Enums\OnboardingStep;
use App\Enums\PaymentMethodType;
use App\Models\Property;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;

/**
 * The Payment methods table of onboarding. A row id must be a payment method of this property that the table shows
 * (not OTA collected). The type counts only on new rows. At least one row of an addable type must be On.
 */
class SavePaymentMethodsRequest extends OnboardingStepRequest
{
    public const MAX_ROWS = 20;

    protected function step(): OnboardingStep
    {
        return OnboardingStep::PaymentMethods;
    }

    /**
     * The `onboarding.only` middleware limits who can reach this route.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $rows = $this->input('rows');

        if (! is_array($rows)) {
            return;
        }

        $this->merge(['rows' => array_map(
            fn (mixed $row): mixed => is_array($row) ? [...$row, 'name' => trim((string) ($row['name'] ?? ''))] : $row,
            array_values($rows),
        )]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $property = $this->route('property');

        if (! $property instanceof Property) {
            throw new LogicException('The property route binding is missing.');
        }

        return [
            'rows' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROWS],
            'rows.*.id' => [
                'nullable', 'integer', 'distinct',
                Rule::exists('payment_methods', 'id')->where(
                    fn (Builder $query) => $query
                        ->where('property_id', $property->id)
                        ->where('type', '!=', PaymentMethodType::OtaCollected->value),
                ),
            ],
            'rows.*.name' => [
                'required', 'string', 'max:100',
                fn (string $attribute, mixed $value, Closure $fail) => $this->checkName($property, $attribute, (string) $value, $fail),
            ],
            'rows.*.type' => ['nullable', Rule::enum(PaymentMethodType::class)->only(PaymentMethodType::addableCases())],
            'rows.*.active' => ['required', 'boolean'],
            'rows.*.requires_reference' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $afterValidator): void {
            $property = $this->route('property');

            if (! $property instanceof Property) {
                return;
            }

            foreach (array_values((array) $this->input('rows')) as $index => $row) {
                if (is_array($row) && empty($row['id']) && empty($row['type'])) {
                    $afterValidator->errors()->add("rows.{$index}.type", __('Choose a type.'));
                }
            }

            if ($afterValidator->errors()->isNotEmpty()) {
                return;
            }

            $savedTypes = $property->paymentMethods()->pluck('type', 'id')->map(fn (PaymentMethodType $type): string => $type->value);
            $anyOn = false;

            foreach ((array) $this->input('rows') as $row) {
                $type = isset($row['id']) ? $savedTypes->get((int) $row['id']) : ($row['type'] ?? null);

                if (in_array($type, array_map(fn (PaymentMethodType $addable): string => $addable->value, PaymentMethodType::addableCases()), true) && $row['active']) {
                    $anyOn = true;
                }
            }

            if (! $anyOn) {
                $afterValidator->errors()->add('rows', __('Keep at least one payment method on.'));
            }
        });
    }

    /**
     * @return list<array{id: int|null, name: string, type: PaymentMethodType|null, active: bool, requires_reference: bool}>
     */
    public function rows(): array
    {
        return array_map(fn (array $row): array => [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'name' => $row['name'],
            'type' => isset($row['id']) || ! isset($row['type']) ? null : PaymentMethodType::from($row['type']),
            'active' => (bool) $row['active'],
            'requires_reference' => (bool) $row['requires_reference'],
        ], array_values($this->validated('rows')));
    }

    /**
     * A new row needs a type. The name must differ from the other rows and from every saved method that has no row of
     * its own (OTA collected), ignoring case.
     */
    private function checkName(Property $property, string $attribute, string $name, Closure $fail): void
    {
        $index = (int) explode('.', $attribute)[1];
        $lower = mb_strtolower($name);
        $rows = array_values((array) $this->input('rows'));

        foreach ($rows as $position => $row) {
            if ($position !== $index && mb_strtolower(trim((string) ($row['name'] ?? ''))) === $lower) {
                $fail(__('Another payment method in this table uses this name.'));

                return;
            }
        }

        $keptIds = array_filter(array_column($rows, 'id'));

        $taken = $property->paymentMethods()
            ->whereRaw('lower(name) = ?', [$lower])
            ->whereNotIn('id', $keptIds)
            ->exists();

        if ($taken) {
            $fail(__('Another payment method already uses this name.'));
        }
    }
}
