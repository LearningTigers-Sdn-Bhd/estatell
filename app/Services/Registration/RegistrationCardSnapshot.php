<?php

namespace App\Services\Registration;

use App\Enums\FieldVisibility;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\Property;
use App\Models\RegistrationCard;
use App\Models\RegistrationCardTerms;
use Carbon\CarbonInterface;

/**
 * Builds the frozen copy of a card at signing: the visible field values, the filled terms, the ticked items and the signer.
 * It reads and writes nothing. The signed card never looks at the live guest or ID document again.
 */
class RegistrationCardSnapshot
{
    public function __construct(private TermsRenderer $renderer) {}

    /**
     * @param  array<string, string>  $stay  from `RegistrationCardStay`
     * @param  list<int>  $ticks  positions of the ticked checklist items
     * @return array<string, mixed>
     */
    public function build(
        Property $property,
        RegistrationCard $card,
        Guest $guest,
        ?GuestIdentityDocument $document,
        RegistrationCardTerms $terms,
        array $stay,
        array $ticks,
        string $signerName,
        CarbonInterface $signedAt,
    ): array {
        $visible = $property->registrationCardFields()->ordered()->get()
            ->filter(fn ($setting): bool => $setting->visibility !== FieldVisibility::Hidden);
        $rendered = $this->renderer->render($terms->body, $this->renderer->valuesFor($property));
        $guestValues = $this->guestValues($guest, $document, $card);
        $fields = [];

        foreach ($visible as $setting) {
            $key = $setting->field->value;
            $value = $guestValues[$key] ?? $stay[$key] ?? null;

            if ($value !== null && $value !== '') {
                $fields[] = ['field' => $key, 'label' => $setting->field->label(), 'value' => $value];
            }
        }

        return [
            'locale' => $terms->locale->value,
            'property_name' => $property->name,
            'guest_id' => $guest->id,
            'fields' => $fields,
            'terms' => [
                'id' => $terms->id,
                'version' => $terms->version,
                'html' => $rendered['html'],
                'items' => array_map(
                    fn (array $item): array => [...$item, 'ticked_at' => in_array($item['position'], $ticks, true) ? $signedAt->toIso8601String() : null],
                    $rendered['items'],
                ),
            ],
            'signer_name' => $signerName,
            'signed_at' => $signedAt->toIso8601String(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function guestValues(Guest $guest, ?GuestIdentityDocument $document, RegistrationCard $card): array
    {
        $address = array_filter([$guest->address_line1, $guest->address_line2, $guest->postal_code, $guest->city, $guest->state, $guest->country_code]);

        return array_filter([
            'full_name' => $guest->full_name,
            'nationality' => $guest->nationality_code,
            'identity_document' => $document === null ? null : $document->type->label().' '.$document->maskedNumber(),
            'date_of_birth' => $guest->date_of_birth?->format('d M Y'),
            'gender' => $guest->gender?->label(),
            'address' => implode(', ', $address),
            'phone' => $guest->phone,
            'email' => $guest->email,
            'vehicle_plate' => $card->vehicle_plate,
            'marketing_consent' => $guest->marketing_consent_at === null ? null : __('Yes'),
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }
}
