<?php

namespace App\Http\Requests\Folio;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The reason dialog on the folio tab. The reason is kept in the page's form state and saved with the first post.
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
            'reason' => ['required', 'string', 'max:500'],
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
