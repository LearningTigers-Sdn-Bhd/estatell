<?php

namespace App\Http\Requests\Folio;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class MoveFolioCreditRequest extends FormRequest
{
    /**
     * The `can:payment.record` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the shape is checked here. `MoveFolioCredit` checks the folios, the credit and what each folio owes.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'moves' => ['required', 'array', 'min:1', 'max:100'],
            'moves.*.folio_id' => ['required', 'integer'],
            'moves.*.amount' => ['required', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],
            'note' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'moves.required' => __('Tick at least one folio to move credit to.'),
            'moves.*.amount.regex' => __('Enter an amount such as 25.00.'),
        ];
    }

    /**
     * @return list<array{folio_id: int, amount: string}>
     */
    public function moves(): array
    {
        return array_values(array_map(fn (array $move): array => ['folio_id' => (int) $move['folio_id'], 'amount' => (string) $move['amount']], $this->validated('moves')));
    }

    public function note(): ?string
    {
        return $this->filled('note') ? $this->string('note')->trim()->toString() : null;
    }
}
