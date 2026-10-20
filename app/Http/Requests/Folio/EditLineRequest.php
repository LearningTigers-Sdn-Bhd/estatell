<?php

namespace App\Http\Requests\Folio;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The Edit line Dialog: the new values, the reason (which starts as the page's reason) and the Dialog's UUID, which is
 * the batch key.
 */
class EditLineRequest extends EditLinePreviewRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'reason' => ['required', 'string', 'max:500'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => __('Describe the reason.'),
        ];
    }
}
