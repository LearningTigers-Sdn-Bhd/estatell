<?php

namespace App\Http\Requests\Keyport;

use App\Enums\KeyportJobType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Staff ask the desk PC for a key card of a stay. Special cards are not an option: only guest card jobs exist.
 */
class StoreKeyCardRequest extends FormRequest
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
            'door_lock_id' => ['required', 'integer'],
            'type' => ['required', Rule::enum(KeyportJobType::class)->only([KeyportJobType::Issue, KeyportJobType::Rewrite, KeyportJobType::Erase])],
            'key' => ['nullable', 'string', 'max:100'],
        ];
    }
}
