<?php

namespace App\Http\Requests\Folio;

use App\Enums\DiscountType;
use App\Enums\PermissionEnum;
use App\Models\Discount;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class QuoteFolioDiscountRequest extends FormRequest
{
    /**
     * The `can:folio.discount` route middleware covers the Percent and unchanged Fixed paths. An Open discount, or any
     * amount that staff enter or change, also needs `folio.discount-open`. A route middleware cannot tell which case
     * this is, so it is checked here. An unknown discount passes: the controller answers 404 and the Action refuses.
     */
    public function authorize(): bool
    {
        $discount = Discount::query()
            ->where('property_id', $this->route('property')->id)
            ->active()
            ->find($this->integer('discount_id'));

        return $discount === null
            || ! $this->entersAmount($discount)
            || $this->user()->can(PermissionEnum::FolioDiscountOpen->value);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'discount_id' => ['required', 'integer'],
            'amount' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.regex' => __('Enter an amount such as 25.00.'),
            'discount_id.required' => __('Choose a discount.'),
        ];
    }

    public function amount(): ?string
    {
        return $this->filled('amount') ? $this->string('amount')->toString() : null;
    }

    /**
     * A Percent discount ignores any amount. An Open discount always needs one. A Fixed discount needs the permission
     * only when the amount differs from its value.
     */
    private function entersAmount(Discount $discount): bool
    {
        return match ($discount->type) {
            DiscountType::Percent => false,
            DiscountType::Open => true,
            DiscountType::Fixed => $this->amount() !== null && bccomp($this->amount(), (string) $discount->value, 2) !== 0,
        };
    }
}
