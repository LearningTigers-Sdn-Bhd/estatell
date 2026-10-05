<?php

namespace App\Http\Requests\Registration;

use App\Enums\Locale;
use App\Services\Registration\TermsRenderer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PublishRegistrationTermsRequest extends FormRequest
{
    private const MAX_CHECKLIST_ITEMS = 10;

    /** The `can:registration-card.manage-terms` route middleware authorizes this request. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $renderer = app(TermsRenderer::class);

        return [
            'locale' => ['required', Rule::enum(Locale::class)],
            'body' => [
                'required', 'string', 'max:30000',
                function (string $attribute, mixed $value, Closure $fail) use ($renderer): void {
                    $unknown = $renderer->unknownPlaceholders((string) $value);

                    if ($unknown !== []) {
                        $fail(__('Unknown placeholder: :names.', ['names' => implode(', ', array_map(fn (string $name): string => '{{'.$name.'}}', $unknown))]));
                    }

                    if (count($renderer->items((string) $value)) > self::MAX_CHECKLIST_ITEMS) {
                        $fail(__('A version can have at most :max checklist items.', ['max' => self::MAX_CHECKLIST_ITEMS]));
                    }
                },
            ],
        ];
    }

    public function locale(): Locale
    {
        return Locale::from($this->validated('locale'));
    }
}
