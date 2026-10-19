<?php

namespace App\Http\Requests\Folio;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The post of the manual folio page: the rows, the reason, the close flag and the form's UUID, which is the batch key.
 */
class StoreManualFolioRequest extends ManualFolioPreviewRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'reason' => ['required', 'string', 'max:500'],
            'close' => ['required', 'boolean'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => __('Give a reason for the manual entries.'),
        ];
    }
}
