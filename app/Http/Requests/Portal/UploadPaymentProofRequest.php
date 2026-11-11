<?php

namespace App\Http\Requests\Portal;

use App\Models\Property;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * A payment proof or slip from the portal: the file (image or PDF, at most 10 MB), Paid into, the amount paid and the
 * bank reference. Paid into must be an available bank account of the property. It is required with 2 or more, filled
 * in with the only one, and empty with none. See docs/06-catalogues/07-bank-accounts.md#rules.
 */
class UploadPaymentProofRequest extends FormRequest
{
    /** @var list<int>|null */
    private ?array $availableAccountIds = null;

    /**
     * The `portal` route middleware checks the company link; the controller finds the booking through it.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $accounts = $this->availableAccountIds();

        if (! filled($this->input('bank_account_id')) && count($accounts) === 1) {
            $this->merge(['bank_account_id' => $accounts[0]]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'bank_account_id' => [Rule::requiredIf(count($this->availableAccountIds()) > 1), 'nullable', 'integer', Rule::in($this->availableAccountIds())],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'bank_account_id.required' => __('Choose the account you paid into.'),
            'bank_account_id.in' => __('Choose one of the accounts shown.'),
        ];
    }

    public function bankAccountId(): ?int
    {
        $id = $this->validated('bank_account_id');

        return $id === null ? null : (int) $id;
    }

    /** @return list<int> */
    private function availableAccountIds(): array
    {
        $property = $this->route('property');
        if (! $property instanceof Property) {
            throw new LogicException('The property route binding is missing.');
        }

        return $this->availableAccountIds ??= array_values(array_map('intval', $property->bankAccounts()->active()->ordered()->pluck('id')->all()));
    }
}
