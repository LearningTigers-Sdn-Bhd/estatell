<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Save the connection: the same settings, for one OTA channel.
 */
class StoreChannelConnectionRequest extends TestChannelConnectionRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [...parent::rules(), 'ota_channel_id' => ['required', 'integer', 'exists:ota_channels,id']];
    }
}
