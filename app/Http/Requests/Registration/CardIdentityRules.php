<?php

namespace App\Http\Requests\Registration;

use App\Enums\IdentityDocumentType;
use App\Services\Guests\CountryOptions;
use App\Services\Guests\IdentityNumber;
use Illuminate\Validation\Rule;

/**
 * The ID document rules of the registration card: pick one of the guest's documents, or type a new one.
 * The staff draft and the guest's signing form share them.
 */
class CardIdentityRules
{
    /**
     * @param  mixed  $identity  the raw `identity` input
     * @return array<string, mixed>|null the input with the number normalized, or null when there is nothing to normalize
     */
    public static function normalize(mixed $identity): ?array
    {
        if (! is_array($identity) || ! is_string($identity['number'] ?? null)) {
            return null;
        }

        $identity['number'] = app(IdentityNumber::class)->normalize($identity['number']);

        return $identity;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public static function for(int $guestId, mixed $type, bool $documentNeeded, bool $numberNeeded): array
    {
        return [
            'identity_document_id' => [
                'nullable', 'integer',
                Rule::exists('guest_identity_documents', 'id')->where('guest_id', $guestId),
                Rule::requiredIf($documentNeeded),
            ],
            'identity' => ['nullable', 'array'],
            'identity.type' => ['required_with:identity.number', 'nullable', Rule::enum(IdentityDocumentType::class)],
            'identity.number' => [
                'nullable', 'string', 'min:4', 'max:40',
                Rule::when($type === IdentityDocumentType::MyKad->value, ['digits:12']),
                Rule::requiredIf($numberNeeded),
            ],
            'identity.issuing_country_code' => ['nullable', Rule::in(app(CountryOptions::class)->codes())],
            'identity.expires_on' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{type: string, number: string, issuing_country_code: string|null, expires_on: string|null}|null
     */
    public static function newDocument(array $validated): ?array
    {
        $identity = $validated['identity'] ?? null;

        return filled($identity['number'] ?? null) ? [
            'type' => $identity['type'],
            'number' => $identity['number'],
            'issuing_country_code' => $identity['issuing_country_code'] ?? null,
            'expires_on' => $identity['expires_on'] ?? null,
        ] : null;
    }
}
