<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A guest's cart: the variants and quantities they chose. Price, stock and availability are checked by the Action.
 */
class PlaceQrOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.variant_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'lines.*.note' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return list<array{variant_id: int, quantity: int, note: ?string}>
     */
    public function cartLines(): array
    {
        return array_map(fn (array $line): array => [
            'variant_id' => (int) $line['variant_id'],
            'quantity' => (int) $line['quantity'],
            'note' => isset($line['note']) && trim((string) $line['note']) !== '' ? trim((string) $line['note']) : null,
        ], $this->validated('lines'));
    }
}
