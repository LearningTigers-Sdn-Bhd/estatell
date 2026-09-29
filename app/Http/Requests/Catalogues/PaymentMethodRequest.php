<?php

namespace App\Http\Requests\Catalogues;

use App\Enums\PaymentMethodType;
use App\Enums\SurchargeType;
use App\Models\ChargeItem;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\TransactionCode;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use LogicException;

class PaymentMethodRequest extends FormRequest
{
    /** The `can:settings.manage` route middleware authorizes this request. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $surchargeType = (string) $this->input('surcharge_type');

        $this->merge([
            'name' => trim((string) $this->input('name')),
            'code' => Str::of((string) $this->input('code'))->trim()->upper()->replaceMatches('/[^A-Z0-9]+/', '_')->trim('_')->toString(),
            'surcharge_value' => $surchargeType === SurchargeType::None->value || ! filled($this->input('surcharge_value'))
                ? null
                : trim((string) $this->input('surcharge_value')),
            'surcharge_charge_item_id' => $surchargeType === SurchargeType::None->value || ! filled($this->input('surcharge_charge_item_id'))
                ? null
                : $this->integer('surcharge_charge_item_id'),
        ]);
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

        $paymentMethod = $this->route('paymentMethod');
        if (! $paymentMethod instanceof PaymentMethod) {
            $paymentMethod = null;
        }

        $isSystem = $paymentMethod instanceof PaymentMethod
            && $paymentMethod->transactionCode()->whereNotNull('system_key')->exists();

        return [
            'name' => [
                'required', 'string', 'max:100',
                function (string $attribute, mixed $value, Closure $fail) use ($property, $paymentMethod): void {
                    $taken = PaymentMethod::query()
                        ->where('property_id', $property->id)
                        ->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])
                        ->when($paymentMethod instanceof PaymentMethod, fn (Builder $query) => $query->whereKeyNot($paymentMethod->id))
                        ->exists();

                    if ($taken) {
                        $fail(__('Another payment method already uses this name.'));
                    }
                },
            ],
            'type' => $paymentMethod instanceof PaymentMethod
                ? ['exclude']
                : ['required', Rule::enum(PaymentMethodType::class)->only(PaymentMethodType::addableCases())],
            'code' => $isSystem ? ['exclude'] : [
                'required', 'string', 'max:20', 'regex:/^[A-Z0-9]+(?:_[A-Z0-9]+)*$/',
                function (string $attribute, mixed $value, Closure $fail) use ($property, $paymentMethod): void {
                    $taken = TransactionCode::query()
                        ->where('property_id', $property->id)
                        ->whereRaw('lower(code) = ?', [mb_strtolower((string) $value)])
                        ->when($paymentMethod instanceof PaymentMethod, fn (Builder $query) => $query->whereKeyNot($paymentMethod->transaction_code_id))
                        ->exists();

                    if ($taken) {
                        $fail(__('The code :code is already in use.', ['code' => $value]));
                    }
                },
            ],
            'surcharge_type' => ['required', Rule::enum(SurchargeType::class)],
            'surcharge_value' => [
                'nullable',
                'required_unless:surcharge_type,'.SurchargeType::None->value,
                'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99',
                Rule::when($this->input('surcharge_type') === SurchargeType::Percent->value, ['max:100']),
            ],
            'surcharge_charge_item_id' => [
                'nullable',
                'required_unless:surcharge_type,'.SurchargeType::None->value,
                'integer',
                function (string $attribute, mixed $value, Closure $fail) use ($property, $paymentMethod): void {
                    if (! is_int($value)) {
                        return;
                    }

                    $available = ChargeItem::query()
                        ->whereBelongsTo($property)
                        ->whereKey($value)
                        ->where(function (Builder $query) use ($paymentMethod): void {
                            $query->active()
                                ->when(
                                    $paymentMethod?->surcharge_charge_item_id !== null,
                                    fn (Builder $query) => $query->orWhereKey($paymentMethod?->surcharge_charge_item_id),
                                );
                        })
                        ->exists();

                    if (! $available) {
                        $fail(__('Choose an active charge item from this property.'));
                    }
                },
            ],
            'requires_reference' => ['required', 'boolean'],
            'active' => ['required', 'boolean'],
        ];
    }
}
