<?php

namespace App\Http\Requests\Folio;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The query of the Move charges page: `to` is the folio the To column starts on, and `line` and `lines` optional charges
 * to tick at the start (the check-out Dialog sends another room's charges as `lines`). The controller finds them inside
 * the reservation (404 otherwise).
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
            'lines' => ['nullable', 'array', 'max:200'],
            'lines.*' => ['integer'],
        ];
    }

    /**
     * The charges to tick at the start, from `line` and `lines`, without repeats. The first one names the folio the
     * To column must not start on.
     *
     * @return list<int>
     */
    public function lineIds(): array
    {
        $ids = $this->filled('line') ? [$this->integer('line')] : [];

        foreach ($this->array('lines') as $id) {
            $ids[] = (int) $id;
        }

        return array_values(array_unique($ids));
    }
}
