<?php

namespace App\Http\Requests\Property;

use App\Models\Property;
use App\Models\PropertyLicence;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

class SavePropertyBusinessRequest extends FormRequest
{
    public const MAX_LICENCES = 20;

    /** The `can:settings.manage` route middleware authorizes this request. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['licences' => self::trimLicences($this->input('licences'))]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $property = $this->route('property');
        if (! $property instanceof Property) {
            throw new LogicException('The property route binding is missing.');
        }

        return self::businessRules($property);
    }

    /**
     * The registered name, numbers and licences rules, shared with the Basics step of onboarding.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public static function businessRules(Property $property): array
    {
        return [
            'legal_name' => ['nullable', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'tin' => ['nullable', 'string', 'max:50'],
            'licences' => ['present', 'array', 'max:'.self::MAX_LICENCES],
            'licences.*.id' => [
                'nullable', 'integer', 'distinct',
                function (string $attribute, mixed $value, Closure $fail) use ($property): void {
                    if ($value !== null && ! PropertyLicence::query()->whereBelongsTo($property)->whereKey($value)->exists()) {
                        $fail(__('This licence no longer exists.'));
                    }
                },
            ],
            'licences.*.issuer' => ['required', 'string', 'max:150'],
            'licences.*.number' => ['required', 'string', 'max:50'],
            'licences.*.expires_on' => ['nullable', 'date_format:Y-m-d'],
            'licences.*.show_on_documents' => ['boolean'],
        ];
    }

    /**
     * Trim the issuer and number of every licence row before validation.
     */
    public static function trimLicences(mixed $licences): mixed
    {
        if (! is_array($licences)) {
            return $licences;
        }

        return array_map(
            fn (mixed $licence): mixed => is_array($licence) ? [
                ...$licence,
                'issuer' => is_string($licence['issuer'] ?? null) ? trim($licence['issuer']) : ($licence['issuer'] ?? null),
                'number' => is_string($licence['number'] ?? null) ? trim($licence['number']) : ($licence['number'] ?? null),
            ] : $licence,
            $licences,
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{legal_name: string|null, registration_number: string|null, tin: string|null}
     */
    public static function businessFrom(array $validated): array
    {
        return [
            'legal_name' => $validated['legal_name'] ?? null,
            'registration_number' => $validated['registration_number'] ?? null,
            'tin' => $validated['tin'] ?? null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $licences  The validated licences rows.
     * @return list<array{id: int|null, issuer: string, number: string, expires_on: string|null, show_on_documents: bool}>
     */
    public static function licencesFrom(array $licences): array
    {
        return array_map(
            fn (array $licence): array => [
                'id' => isset($licence['id']) ? (int) $licence['id'] : null,
                'issuer' => $licence['issuer'],
                'number' => $licence['number'],
                'expires_on' => $licence['expires_on'] ?? null,
                'show_on_documents' => (bool) ($licence['show_on_documents'] ?? false),
            ],
            array_values($licences),
        );
    }

    /**
     * @return array{legal_name: string|null, registration_number: string|null, tin: string|null}
     */
    public function business(): array
    {
        return self::businessFrom($this->validated());
    }

    /**
     * @return list<array{id: int|null, issuer: string, number: string, expires_on: string|null, show_on_documents: bool}>
     */
    public function licences(): array
    {
        return self::licencesFrom($this->validated('licences'));
    }
}
