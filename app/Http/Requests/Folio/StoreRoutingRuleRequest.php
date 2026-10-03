<?php

namespace App\Http\Requests\Folio;

use App\Enums\TransactionCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoutingRuleRequest extends FormRequest
{
    public const string CHARGES_CATEGORY = 'category';

    public const string CHARGES_CODE = 'code';

    public const string CHARGES_ALL = 'all';

    /**
     * The `can:folio.transfer` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the shape is checked here. `charges` says what the rule matches: one category of a charge item, one charge
     * code, or all charges. A null unit means all units. The controller finds the target folio inside the reservation,
     * and `CreateRoutingRule` refuses with a code.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reservation_unit_id' => ['nullable', 'integer'],
            'charges' => ['required', Rule::in([self::CHARGES_CATEGORY, self::CHARGES_CODE, self::CHARGES_ALL])],
            'category' => ['required_if:charges,'.self::CHARGES_CATEGORY, 'nullable', Rule::in(array_map(fn (TransactionCategory $category): string => $category->value, TransactionCategory::routableCases()))],
            'transaction_code_id' => ['required_if:charges,'.self::CHARGES_CODE, 'nullable', 'integer'],
            'target_folio_id' => ['required', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category.required_if' => __('Choose a category.'),
            'transaction_code_id.required_if' => __('Choose a charge code.'),
            'target_folio_id.required' => __('Choose the folio the charges go to.'),
        ];
    }

    /**
     * The category the rule matches, or null when it matches a code or all charges.
     */
    public function category(): ?TransactionCategory
    {
        return $this->input('charges') === self::CHARGES_CATEGORY ? TransactionCategory::from($this->string('category')->toString()) : null;
    }

    /**
     * The charge code the rule matches, or null when it matches a category or all charges.
     */
    public function transactionCodeId(): ?int
    {
        return $this->input('charges') === self::CHARGES_CODE ? $this->integer('transaction_code_id') : null;
    }

    public function reservationUnitId(): ?int
    {
        return $this->filled('reservation_unit_id') ? $this->integer('reservation_unit_id') : null;
    }
}
