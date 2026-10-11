<?php

namespace App\Http\Requests\Folio;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The query of the Move charges page: `to` is the folio the To column starts on and `line` an optional charge to tick
 * at the start. The controller finds both inside the reservation (404 otherwise).
 */
class MoveChargesPageRequest extends FormRequest
{
    /**
     * The `can:folio.transfer` route middleware authorizes this request.
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
            'to' => ['nullable', 'integer'],
            'line' => ['nullable', 'integer'],
        ];
    }
}
