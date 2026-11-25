<?php

namespace App\Http\Requests\Api;

use App\Enums\AiChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One guest message from n8n for the chat API. `thread` is the channel's own chat id, such as the WhatsApp chat id. It
 * is only ever stored as an HMAC. See docs/35-ai-assistant/03-places-and-routes.md#request.
 */
class SendAiApiMessageRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'channel' => ['required', Rule::enum(AiChannel::class)],
            'thread' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:2000'],
            'guest_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function channel(): AiChannel
    {
        return $this->enum('channel', AiChannel::class) ?? AiChannel::Other;
    }

    public function guestName(): ?string
    {
        return $this->filled('guest_name') ? trim($this->string('guest_name')->toString()) : null;
    }
}
