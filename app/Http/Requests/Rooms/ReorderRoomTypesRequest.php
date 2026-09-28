<?php

namespace App\Http\Requests\Rooms;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReorderRoomTypesRequest extends FormRequest
{
    /**
     * The `can:rooms.manage` route middleware authorizes this request.
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
            'order' => ['required', 'array'],
            'order.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * @return list<int>
     */
    public function orderedIds(): array
    {
        return array_map('intval', array_values($this->validated('order')));
    }
}
