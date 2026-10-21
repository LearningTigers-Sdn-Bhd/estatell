<?php

namespace App\Http\Requests\Partner;

use App\Services\Platform\PartnerSignupMessage;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePartnerSignupMessageRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'template' => ['bail', 'required', 'string', 'max:'.PartnerSignupMessage::MAX_LENGTH, function (string $attribute, mixed $value, \Closure $fail): void {
                if (! str_contains($value, '{link}')) {
                    $fail(__('Add {link} where the signup link should go.'));
                }

                $unknown = app(PartnerSignupMessage::class)->unknownPlaceholders($value);

                if ($unknown !== []) {
                    $fail(__('Unknown placeholder: :names. Use {partner} or {link}.', ['names' => '{'.implode('}, {', $unknown).'}']));
                }
            }],
        ];
    }
}
