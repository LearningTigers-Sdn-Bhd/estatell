<?php

namespace App\Http\Requests\Folio;

use App\Actions\Folio\SaveOldFoliosResult;
use App\Data\Folio\OldFolioRowData;
use App\Enums\OldFolioRowKind;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The save of the old folio page: every row of every tab and the old folio number of each folio. Only the shape is
 * checked here; `SaveOldFolios` refuses a row with a code, and the controller finds the folios and units inside the
 * reservation (404 otherwise). The `can:folio.manual` route middleware authorizes this request.
 */
class StoreOldFoliosRequest extends FormRequest
{
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
            'rows' => ['present', 'array', 'max:'.SaveOldFoliosResult::MAX_ROWS],
            'rows.*.key' => ['required', 'uuid', 'distinct'],
            'rows.*.kind' => ['required', Rule::enum(OldFolioRowKind::class)],
            'rows.*.service_date' => ['required', 'string', 'max:10'],
            'rows.*.folio_id' => ['required', 'integer'],
            'rows.*.unit_id' => ['nullable', 'integer'],
            'rows.*.charge_item_id' => ['nullable', 'integer'],
            'rows.*.tax_id' => ['nullable', 'integer'],
            'rows.*.payment_method_id' => ['nullable', 'integer'],
            'rows.*.description' => ['nullable', 'string', 'max:120'],
            'rows.*.quantity' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'rows.*.amount' => ['nullable', 'string', 'max:14'],
            'legacy_numbers' => ['nullable', 'array'],
            'legacy_numbers.*' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * @return list<OldFolioRowData>
     */
    public function rows(): array
    {
        return array_values(array_map(fn (array $row): OldFolioRowData => new OldFolioRowData(
            key: (string) $row['key'],
            kind: OldFolioRowKind::from($row['kind']),
            serviceDate: (string) $row['service_date'],
            folioId: (int) $row['folio_id'],
            unitId: isset($row['unit_id']) ? (int) $row['unit_id'] : null,
            chargeItemId: isset($row['charge_item_id']) ? (int) $row['charge_item_id'] : null,
            taxId: isset($row['tax_id']) ? (int) $row['tax_id'] : null,
            paymentMethodId: isset($row['payment_method_id']) ? (int) $row['payment_method_id'] : null,
            description: isset($row['description']) ? (string) $row['description'] : null,
            quantity: (int) ($row['quantity'] ?? 1),
            amount: trim((string) ($row['amount'] ?? '')),
        ), (array) $this->input('rows', [])));
    }

    /**
     * The old folio number of each folio by folio id.
     *
     * @return array<int, string|null>
     */
    public function legacyNumbers(): array
    {
        $numbers = [];

        foreach ((array) $this->input('legacy_numbers', []) as $folioId => $number) {
            $numbers[(int) $folioId] = $number === null ? null : (string) $number;
        }

        return $numbers;
    }
}
