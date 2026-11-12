<?php

namespace App\Http\Requests\Notifications;

use App\Enums\GuestEmail;
use App\Services\Notifications\GuestEmailText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** A property's own subject and message for a guest email. Only the email's own placeholders are allowed. */
class GuestEmailRequest extends FormRequest
{
    /**
     * The `can:settings.manage` route middleware authorizes this request.
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
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $email = $this->route('email');
            if (! $email instanceof GuestEmail) {
                return;
            }

            foreach (['subject', 'body'] as $field) {
                $unknown = app(GuestEmailText::class)->unknownPlaceholders($email, (string) $this->input($field));
                if ($unknown !== []) {
                    $validator->errors()->add($field, __('Unknown placeholder: :names. Use only the placeholders listed below.', [
                        'names' => implode(', ', array_map(fn (string $name): string => '{'.$name.'}', $unknown)),
                    ]));
                }
            }
        }];
    }
}
