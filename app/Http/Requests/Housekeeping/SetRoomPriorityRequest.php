<?php

namespace App\Http\Requests\Housekeeping;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SetRoomPriorityRequest extends FormRequest
{
    /**
     * The `can:housekeeping.update` route middleware authorizes this request.
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
            'priority' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function note(): ?string
    {
        $note = trim((string) $this->validated('note'));

        return $note === '' ? null : $note;
    }
}
