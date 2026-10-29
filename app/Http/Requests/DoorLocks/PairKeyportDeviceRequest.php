<?php

namespace App\Http\Requests\DoorLocks;

/**
 * The body of `POST /pair`.
 */
class PairKeyportDeviceRequest extends KeyportRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'pairing_code' => ['required', 'string', 'max:20'],
            'device_name' => ['required', 'string', 'max:80'],
            'platform' => ['nullable', 'string', 'max:40'],
            'agent_version' => ['nullable', 'string', 'max:40'],
        ];
    }
}
