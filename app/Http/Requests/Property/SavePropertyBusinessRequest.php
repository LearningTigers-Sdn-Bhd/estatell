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
        $licences = $this->input('licences');

        if (! is_array($licences)) {
            return;
        }

        $this->merge(['licences' => array_map(
            fn (mixed $licence): mixed => is_array($licence) ? [
                ...$licence,
                'issuer' => is_string($licence['issuer'] ?? null) ? trim($licence['issuer']) : ($licence['issuer'] ?? null),
                'number' => is_string($licence['number'] ?? null) ? trim($licence['number']) : ($licence['number'] ?? null),
            ] : $licence,
            $licences,
        )]);
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
     * @return array{legal_name: string|null, registration_number: string|null, tin: string|null}
     */
    public function business(): array
    {
        return [
            'legal_name' => $this->validated('legal_name'),
            'registration_number' => $this->validated('registration_number'),
            'tin' => $this->validated('tin'),
        ];
    }

    /**
     * @return list<array{id: int|null, issuer: string, number: string, expires_on: string|null, show_on_documents: bool}>
     */
    public function licences(): array
    {
        return array_map(
            fn (array $licence): array => [
                'id' => isset($licence['id']) ? (int) $licence['id'] : null,
                'issuer' => $licence['issuer'],
                'number' => $licence['number'],
                'expires_on' => $licence['expires_on'] ?? null,
                'show_on_documents' => (bool) ($licence['show_on_documents'] ?? false),
            ],
            array_values($this->validated('licences')),
        );
    }
}
