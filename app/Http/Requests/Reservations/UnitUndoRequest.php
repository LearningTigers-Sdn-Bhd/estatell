<?php

namespace App\Http\Requests\Reservations;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Undo check-in and Undo check-out forms. They send a reason and nothing else.
 */
class UnitUndoRequest extends FormRequest
{
    /**
     * The `can:front-desk.undo-check-in` or `can:front-desk.undo-check-out` route middleware authorizes this request.
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
}
