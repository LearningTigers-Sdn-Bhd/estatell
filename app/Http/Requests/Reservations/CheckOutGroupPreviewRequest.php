<?php

namespace App\Http\Requests\Reservations;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The same body as the save with every part optional. Without `rows` the preview totals the prefilled choices. It
 * checks no permission: the choices it offers already follow them.
 */
class CheckOutGroupPreviewRequest extends CheckOutGroupRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'idempotency_key' => ['nullable', 'uuid'],
            'rows' => ['nullable', 'array', 'max:300'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [];
    }

    public function hasRows(): bool
    {
        return $this->has('rows');
    }
}
