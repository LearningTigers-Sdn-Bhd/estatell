<?php

namespace App\Http\Requests\DoorLocks;

/**
 * The health a Keyport PC sends with `POST /jobs/claim`.
 */
class KeyportClaimRequest extends KeyportRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'agent_version' => ['nullable', 'string', 'max:40'],
            'simulator' => ['sometimes', 'boolean'],
            'readers' => ['sometimes', 'array', 'max:10'],
            'readers.*.kind' => ['required', 'string', 'max:20'],
            'readers.*.state' => ['required', 'string', 'max:20'],
            'client_code_present' => ['sometimes', 'boolean'],
            'busy' => ['sometimes', 'boolean'],
        ];
    }
}
