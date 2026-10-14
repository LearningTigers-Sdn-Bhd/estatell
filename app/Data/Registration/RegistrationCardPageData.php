<?php

namespace App\Data\Registration;

use App\Data\Shared\BadgeData;
use App\Enums\FieldVisibility;
use App\Enums\Locale;
use App\Enums\RegistrationCardStatus;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\RegistrationCard;
use App\Models\RegistrationCardFieldSetting;
use App\Models\ReservationUnit;
use App\Services\Registration\ResolvedTerms;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

/**
 * Everything the staff registration card page shows for one guest on one unit.
 * A signed card sends only what it froze at signing.
 */
class RegistrationCardPageData extends Data
{
    /**
     * @param  list<array{field: string, label: string, group: string, visibility: string, value: string|null}>  $fields  Visible fields in card order. `value` is the printed text of a Stay or Prices field.
     * @param  array<string, string|bool|null>  $values  The guest's current answers, keyed by form field name
     * @param  list<array{id: int, label: string}>  $documents  The guest's ID documents, masked
     * @param  list<array{position: int, text: string}>  $checklist
     * @param  list<array{value: string, label: string}>  $localeOptions
     * @param  array<string, string>  $labels  English text to the text in the open language
     * @param  list<array{label: string, value: string|null}>|null  $signedFields  Set once the card is signed
     * @param  list<int>|null  $signedTicks  Positions of the checklist items ticked at signing, set once the card is signed
     * @param  list<array{label: string, value: string}>|null  $draftFields  The current answers of a draft, for the staff page
     * @param  int|null  $walkPosition  This guest's place among the unit's guests that need a card, or null when not one of them
     */
    public function __construct(
        public int $unitGuestId,
        public int $reservationId,
        public string $guestName,
        public string $unitLabel,
        public int $cardId,
        public ?string $number,
        public BadgeData $status,
        public ?string $signedAt,
        public ?string $signerName,
        public ?string $voidReason,
        public string $locale,
        public array $localeOptions,
        public array $fields,
        public array $values,
        public array $documents,
        public string $termsText,
        public array $checklist,
        public array $labels,
        public bool $canVoid,
        public ?array $signedFields,
        public ?array $signedTicks,
        public ?array $draftFields,
        public ?int $cardDocumentId,
        public ?int $walkPosition,
        public int $walkTotal,
    ) {}

    /**
     * @param  Collection<int, RegistrationCardFieldSetting>  $settings  every field setting of the property, in card order
     * @param  Collection<int, GuestIdentityDocument>  $documents  the guest's ID documents
     * @param  array{html: string, text: string, items: list<array{position: int, text: string}>}  $rendered  the terms, filled and rendered
     * @param  array<string, string>  $stay  printed Stay and Prices values
     * @param  array<string, string>  $labels
     * @param  list<array{field: string, label: string, value: string}>|null  $draftFields
     */
    public static function forCard(
        RegistrationCard $card,
        Guest $guest,
        ReservationUnit $unit,
        int $unitGuestId,
        Collection $settings,
        ResolvedTerms $resolved,
        array $rendered,
        array $stay,
        array $labels,
        Collection $documents,
        bool $canVoid,
        ?array $draftFields,
        ?int $walkPosition,
        int $walkTotal,
    ): self {
        $visible = $settings->filter(fn (RegistrationCardFieldSetting $setting): bool => $setting->visibility !== FieldVisibility::Hidden);
        $signed = $card->snapshot;

        return new self(
            unitGuestId: $unitGuestId,
            reservationId: $unit->reservation_id,
            guestName: $guest->full_name,
            unitLabel: $unit->roomType->name.' · '.$unit->arrival_date->format('d M').' – '.$unit->departure_date->format('d M Y'),
            cardId: $card->id,
            number: $card->number,
            status: BadgeData::fromRegistrationCardStatus($card->status),
            signedAt: $card->signed_at?->toIso8601String(),
            signerName: $card->signer_name,
            voidReason: $card->void_reason,
            locale: $resolved->locale->value,
            localeOptions: array_map(fn (Locale $locale): array => ['value' => $locale->value, 'label' => $locale->label()], $resolved->locales),
            fields: array_values($visible->map(fn (RegistrationCardFieldSetting $setting): array => [
                'field' => $setting->field->value,
                'label' => $setting->field->label(),
                'group' => $setting->field->group()->value,
                'visibility' => $setting->visibility->value,
                'value' => $stay[$setting->field->value] ?? null,
            ])->all()),
            values: [
                'full_name' => $guest->full_name,
                'nationality_code' => $guest->nationality_code,
                'date_of_birth' => $guest->date_of_birth?->format('Y-m-d'),
                'gender' => $guest->gender?->value,
                'phone' => $guest->phone,
                'email' => $guest->email,
                'address_line1' => $guest->address_line1,
                'address_line2' => $guest->address_line2,
                'city' => $guest->city,
                'state' => $guest->state,
                'postal_code' => $guest->postal_code,
                'country_code' => $guest->country_code,
                'vehicle_plate' => $card->vehicle_plate,
                'remarks' => $card->remarks,
                'marketing_consent' => $guest->marketing_consent_at !== null,
            ],
            documents: array_values($documents->map(fn (GuestIdentityDocument $document): array => ['id' => $document->id, 'label' => $document->type->label().' '.$document->maskedNumber()])->all()),
            termsText: $rendered['text'],
            checklist: $rendered['items'],
            labels: $labels,
            canVoid: $canVoid && $card->status !== RegistrationCardStatus::Void,
            signedFields: $signed === null ? null : array_map(fn (array $field): array => ['label' => $field['label'], 'value' => $field['value']], $signed['fields']),
            signedTicks: $signed === null ? null : array_values(array_map(fn (array $item): int => $item['position'], array_filter($signed['terms']['items'], fn (array $item): bool => $item['ticked_at'] !== null))),
            draftFields: $draftFields === null ? null : array_map(fn (array $field): array => ['label' => $field['label'], 'value' => $field['value']], $draftFields),
            cardDocumentId: $card->identity_document_id,
            walkPosition: $walkPosition,
            walkTotal: $walkTotal,
        );
    }
}
