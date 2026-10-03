<?php

namespace App\Http\Requests\Folio;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreFolioTransferRequest extends FormRequest
{
    /**
     * The `can:folio.transfer` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the shape is checked here. The controller finds the lines and the target inside the reservation, and
     * `TransferLines` refuses with a code.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'line_ids' => ['required', 'array', 'min:1'],
            'line_ids.*' => ['integer'],
            'target_folio_id' => ['required', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target_folio_id.required' => __('Choose the folio to move it to.'),
        ];
    }
}
