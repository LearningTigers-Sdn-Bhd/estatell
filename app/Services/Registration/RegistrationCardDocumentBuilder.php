<?php

namespace App\Services\Registration;

use App\Enums\FieldVisibility;
use App\Enums\Locale;
use App\Enums\RegistrationCardField;
use App\Models\Property;
use App\Models\RegistrationCard;

/**
 * Collects the content of a card PDF: a signed card from its frozen snapshot, a blank form from the draft.
 * It reads and writes nothing else.
 */
class RegistrationCardDocumentBuilder
{
    public function __construct(
        private RegistrationTermsResolver $termsResolver,
        private TermsRenderer $termsRenderer,
        private RegistrationCardStay $stay,
        private RegistrationCardSnapshot $snapshot,
    ) {}

    /**
     * A signed card, in the language it was signed in. It never reads the live guest, ID document or terms.
     */
    public function forSigned(RegistrationCard $card): RegistrationCardDocument
    {
        /** @var array<string, mixed> $snapshot */
        $snapshot = $card->snapshot ?? [];
        $locale = Locale::from($snapshot['locale']);
        $property = $card->property()->with('settings')->firstOrFail();
        $signature = $card->getFirstMedia(RegistrationCard::SIGNATURE);

        return new RegistrationCardDocument(
            $locale,
            $snapshot['property_name'],
            $card->number,
            $this->translatedFields($locale, $this->withoutMarketing($snapshot['fields'])),
            $snapshot['terms']['html'],
            array_map(fn (array $item): array => ['text' => $item['text'], 'ticked' => $item['ticked_at'] !== null], $snapshot['terms']['items']),
            $card->signer_name,
            $card->signed_at?->setTimezone($property->settings->time_zone ?? config('app.timezone'))->format('d M Y, H:i'),
            $signature === null ? null : 'data:image/png;base64,'.base64_encode((string) stream_get_contents($signature->stream())),
            in_array(RegistrationCardField::MarketingConsent->value, array_column($snapshot['fields'], 'field'), true) ? true : null,
        );
    }

    /**
     * A draft as a form to print: every visible field with the answers so far, empty items, no signature.
     * Returns null when the property has no published terms.
     */
    public function forBlank(Property $property, RegistrationCard $card, ?Locale $requested): ?RegistrationCardDocument
    {
        $unitGuest = $card->unitGuest()->with(['guest', 'reservationUnit.roomType'])->firstOrFail();
        $guest = $unitGuest->guest;
        $resolved = $this->termsResolver->resolve($property, $unitGuest->reservationUnit->roomType, $guest, $requested);

        if ($resolved === null) {
            return null;
        }

        $rendered = $this->termsRenderer->render($resolved->terms->body, $this->termsRenderer->valuesFor($property));
        $answers = collect($this->snapshot->fields($property, $card, $guest, $card->identityDocument, $this->stay->valuesFor($unitGuest, $property)))->pluck('value', 'field');
        $fields = $property->registrationCardFields()->ordered()->get()
            ->filter(fn ($setting): bool => $setting->visibility !== FieldVisibility::Hidden)
            ->map(fn ($setting): array => ['field' => $setting->field->value, 'label' => $setting->field->label(), 'value' => (string) ($answers[$setting->field->value] ?? '')])
            ->values()
            ->all();

        return new RegistrationCardDocument(
            $resolved->locale,
            $property->name,
            null,
            $this->translatedFields($resolved->locale, $this->withoutMarketing($fields)),
            $rendered['html'],
            array_map(fn (array $item): array => ['text' => $item['text'], 'ticked' => false], $rendered['items']),
            null,
            null,
            null,
            in_array(RegistrationCardField::MarketingConsent->value, array_column($fields, 'field'), true) ? false : null,
        );
    }

    /**
     * The offers line is drawn as a checkbox, not as a field.
     *
     * @param  list<array{field: string, label: string, value: string}>  $fields
     * @return list<array{field: string, label: string, value: string}>
     */
    private function withoutMarketing(array $fields): array
    {
        return array_values(array_filter($fields, fn (array $field): bool => $field['field'] !== RegistrationCardField::MarketingConsent->value));
    }

    /**
     * @param  list<array{field: string, label: string, value: string}>  $fields
     * @return list<array{section: string, label: string, value: string}>
     */
    private function translatedFields(Locale $locale, array $fields): array
    {
        return array_map(function (array $field) use ($locale): array {
            $case = RegistrationCardField::tryFrom($field['field']);

            return [
                'section' => $case?->group()->value ?? 'guest',
                'label' => __($case?->label() ?? $field['label'], [], $locale->value),
                'value' => $field['value'],
            ];
        }, $fields);
    }
}
