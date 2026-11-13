<?php

namespace App\Http\Requests\Admin;

use App\Enums\WebhookEvent;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The `can:admin` route middleware authorizes this request.
 */
class SendTestWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'event' => ['required', 'string', Rule::enum(WebhookEvent::class)],
        ];
    }
}
