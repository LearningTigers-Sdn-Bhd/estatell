<?php

namespace App\Http\Requests\Folio;

use App\Enums\ManualFolioReason;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * The post of the manual folio page: the rows, the reason (picked, with details for Other), the close flag and the
 * form's UUID, which is the batch key.
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
            'reason_option' => ['required', Rule::enum(ManualFolioReason::class)],
            'reason_details' => ['nullable', 'required_if:reason_option,'.ManualFolioReason::Other->value, 'string', 'max:500'],
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
            'reason_option.required' => __('Choose a reason for the manual entries.'),
            'reason_details.required_if' => __('Describe the reason.'),
        ];
    }

    /**
     * The reason the batch keeps: the picked reason's label, or the typed details for Other.
     */
    public function reason(): string
    {
        return $this->enum('reason_option', ManualFolioReason::class)->text($this->string('reason_details')->toString());
    }
}
