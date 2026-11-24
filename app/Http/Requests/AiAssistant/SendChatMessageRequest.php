<?php

namespace App\Http\Requests\AiAssistant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Laravel\Ai\Vercel\Chat;
use Laravel\Ai\Vercel\Vercel;

/**
 * One guest message for the AI assistant: text only, so no file a guest sends reaches the model. `id` is the browser's
 * thread id; a stay route ignores it and uses the stay's own thread. `language` is the browser's language (ISO 639-1),
 * kept on the thread for the Usage tab.
 */
class SendChatMessageRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'id' => [Rule::requiredIf($this->route('token') === null), 'nullable', 'uuid'],
            'text' => ['required', 'string', 'max:2000'],
            'language' => ['nullable', 'string', 'regex:/^[a-z]{2}$/'],
        ];
    }

    public function threadId(): ?string
    {
        return $this->filled('id') ? $this->string('id')->toString() : null;
    }

    public function language(): ?string
    {
        return $this->filled('language') ? $this->string('language')->toString() : null;
    }

    /**
     * The message as the SDK's chat input, with one text part.
     */
    public function chat(): Chat
    {
        return Vercel::chat([['role' => 'user', 'parts' => [['type' => 'text', 'text' => trim($this->string('text')->toString())]]]]);
    }
}
