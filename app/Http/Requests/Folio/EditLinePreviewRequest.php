<?php

namespace App\Http\Requests\Folio;

use App\Data\Folio\EditLineData;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The values of the Edit line Dialog, for the preview. Only the shape is checked here: `EditLinePreview` judges them.
 */
class EditLinePreviewRequest extends FormRequest
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
            'description' => ['nullable', 'string', 'max:200'],
            'amount' => ['nullable', 'string', 'max:14'],
            'quantity' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'tax_ids' => ['nullable', 'array', 'max:20'],
            'tax_ids.*' => ['integer'],
        ];
    }

    public function edit(): EditLineData
    {
        return new EditLineData(
            (string) $this->input('description', ''),
            (string) $this->input('amount', ''),
            (int) $this->input('quantity', 0),
            array_values(array_map(intval(...), (array) $this->input('tax_ids', []))),
        );
    }
}
