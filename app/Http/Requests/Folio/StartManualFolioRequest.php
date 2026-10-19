<?php

namespace App\Http\Requests\Folio;

use App\Enums\ManualFolioReason;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The reason dialog on the folio tab: a picked reason, with details for Other. The reason is kept in the page's form state and saved with the first post.
 */
class StartManualFolioRequest extends FormRequest
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
            'reason_option' => ['required', Rule::enum(ManualFolioReason::class)],
            'reason_details' => ['nullable', 'required_if:reason_option,'.ManualFolioReason::Other->value, 'string', 'max:500'],
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
