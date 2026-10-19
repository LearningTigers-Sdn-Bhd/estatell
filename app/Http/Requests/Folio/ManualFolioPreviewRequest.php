<?php

namespace App\Http\Requests\Folio;

use App\Data\Folio\ManualRowData;
use App\Enums\ManualRowType;
use App\Enums\PaymentPurpose;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The draft rows of the manual folio page. The preview and the post send the same rows. Only the shape is checked
 * here; a draft row may be incomplete, and `ManualFolioPreview` and `PostManualBatch` refuse it with a code. The
 * controller finds the folios, payments and rooms inside the reservation (404 otherwise).
 */
class ManualFolioPreviewRequest extends FormRequest
{
    /**
     * The `can:folio.manual` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'rows' => ['present', 'array', 'max:100'],
            'rows.*.key' => ['required', 'string', 'max:64', 'distinct'],
            'rows.*.type' => ['required', Rule::enum(ManualRowType::class)],
            'rows.*.service_date' => ['required', 'string', 'max:10'],
            'rows.*.folio_id' => ['required', 'integer'],
            'rows.*.unit_id' => ['nullable', 'integer'],
            'rows.*.charge_item_id' => ['nullable', 'integer'],
            'rows.*.description' => ['nullable', 'string', 'max:120'],
            'rows.*.quantity' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'rows.*.amount' => ['nullable', 'string', 'max:14'],
            'rows.*.tax_ids' => ['nullable', 'array', 'max:20'],
            'rows.*.tax_ids.*' => ['integer'],
            'rows.*.payment_method_id' => ['nullable', 'integer'],
            'rows.*.purpose' => ['nullable', Rule::enum(PaymentPurpose::class)],
            'rows.*.payment_id' => ['nullable', 'integer'],
            'rows.*.reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return list<ManualRowData>
     */
    public function rows(): array
    {
        return array_values(array_map(fn (array $row): ManualRowData => new ManualRowData(
            key: (string) $row['key'],
            type: ManualRowType::from($row['type']),
            serviceDate: (string) $row['service_date'],
            folioId: (int) $row['folio_id'],
            unitId: isset($row['unit_id']) ? (int) $row['unit_id'] : null,
            chargeItemId: isset($row['charge_item_id']) ? (int) $row['charge_item_id'] : null,
            description: $row['description'] ?? null,
            quantity: (int) ($row['quantity'] ?? 0),
            amount: (string) ($row['amount'] ?? ''),
            taxIds: array_key_exists('tax_ids', $row) && $row['tax_ids'] !== null ? array_values(array_map('intval', $row['tax_ids'])) : null,
            paymentMethodId: isset($row['payment_method_id']) ? (int) $row['payment_method_id'] : null,
            paymentId: isset($row['payment_id']) ? (int) $row['payment_id'] : null,
            reference: $row['reference'] ?? null,
            purpose: isset($row['purpose']) ? PaymentPurpose::from($row['purpose']) : null,
        ), $this->validated('rows')));
    }
}
