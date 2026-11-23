<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Staff attach a pending POS posting to a unit, or ignore it with a note. The route's `can:` middleware checks `pos.resolve`.
 */
class ResolvePosPostingRequest extends FormRequest
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
            'action' => ['required', 'in:attach,ignore'],
            'reservation_unit_id' => ['nullable', 'integer', 'required_if:action,attach'],
            'folio_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:500', 'required_if:action,ignore'],
        ];
    }
}
