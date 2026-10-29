<?php

namespace App\Http\Requests\DoorLocks;

use App\Enums\KeyportJobStatus;
use Illuminate\Validation\Rule;

/**
 * The body of `POST /jobs/{id}/result`.
 */
class KeyportJobResultRequest extends KeyportRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(KeyportJobStatus::reportable())],
            'serial' => ['nullable', 'string', 'max:32'],
            'error_code' => ['nullable', 'string', 'max:64'],
            'error_message' => ['nullable', 'string', 'max:500'],
            'card' => ['nullable', 'array'],
            'card.lock_no' => ['nullable', 'string', 'max:32'],
            'card.check_out' => ['nullable', 'string', 'max:32'],
            'card.flags' => ['nullable', 'integer'],
            'finished_at' => ['nullable', 'string', 'max:40'],
        ];
    }
}
