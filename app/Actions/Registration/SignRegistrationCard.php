<?php

namespace App\Actions\Registration;

use App\Actions\Guests\AddIdentityDocument;
use App\Actions\Guests\UpdateGuest;
use App\Data\Registration\SignRegistrationCardData;
use App\Enums\DocumentType;
use App\Enums\RegistrationCardStatus;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\RegistrationCard;
use App\Models\User;
use App\Services\NightAudit\NextDocumentNumber;
use App\Services\Registration\RegistrationCardRequirement;
use App\Services\Registration\RegistrationCardSnapshot;
use App\Services\Registration\RegistrationCardStay;
use App\Services\Registration\RegistrationTermsResolver;
use App\Services\Registration\TermsRenderer;
use Illuminate\Support\Facades\DB;

class SignRegistrationCard
{
    private const string PNG_PREFIX = 'data:image/png;base64,';

    private const string PNG_MAGIC = "\x89PNG\r\n\x1a\n";

    private const int MAX_SIGNATURE_BYTES = 1_000_000;

    public function __construct(
        private RegistrationCardRequirement $requirement,
        private UpdateGuest $updateGuest,
        private AddIdentityDocument $addIdentityDocument,
        private RegistrationTermsResolver $termsResolver,
        private TermsRenderer $termsRenderer,
        private RegistrationCardStay $stay,
        private RegistrationCardSnapshot $snapshot,
        private NextDocumentNumber $nextDocumentNumber,
    ) {}

    /**
     * Sign a draft card: save the guest's details, resolve the terms again, check every checklist item is ticked,
     * store the signature and freeze the snapshot.
     *
     * Returns false, without writing, when the card is not a draft, the unit or guest is closed, the language has
     * no published terms, the ticks do not cover every checklist item, or the signature is not a PNG image.
     * Required fields are checked by the Form Request.
     */
    public function __invoke(User $actor, RegistrationCard $card, SignRegistrationCardData $data): bool
    {
        $signature = $this->signaturePng($data->signature);

        if ($signature === null) {
            return false;
        }

        return DB::transaction(function () use ($actor, $card, $data, $signature): bool {
            $card = RegistrationCard::query()->lockForUpdate()->with('unitGuest.reservationUnit.roomType', 'property.settings')->findOrFail($card->id);

            if ($card->status !== RegistrationCardStatus::Draft || ! $this->requirement->canHaveCard($card->unitGuest)) {
                return false;
            }

            $property = $card->property;
            $guest = Guest::query()->findOrFail($card->unitGuest->guest_id);
            $resolved = $this->termsResolver->resolve($property, $card->unitGuest->reservationUnit->roomType, $guest, $data->locale);

            if ($resolved === null || $resolved->locale !== $data->locale || ! $this->ticksCover($resolved->terms->body, $data->ticks)) {
                return false;
            }

            if (! ($this->updateGuest)($actor, $guest, [...$data->profile, 'preferred_locale' => $data->locale])) {
                return false;
            }

            if ($data->marketingConsent && $guest->marketing_consent_at === null) {
                $guest->forceFill(['marketing_consent_at' => now()])->save();
            }

            $document = $this->identityDocument($actor, $guest, $data);
            $signedAt = now();
            $guest->refresh();
            $card->vehicle_plate = $data->vehiclePlate;
            $card->remarks = $data->remarks;

            $card->forceFill([
                'status' => RegistrationCardStatus::Signed,
                'number' => ($this->nextDocumentNumber)($property, DocumentType::RegistrationCard)->reference,
                'identity_document_id' => $document?->id,
                'terms_id' => $resolved->terms->id,
                'signed_at' => $signedAt,
                'signer_name' => $data->signerName,
                'snapshot' => $this->snapshot->build(
                    $property,
                    $card,
                    $guest,
                    $document,
                    $resolved->terms,
                    $this->stay->valuesFor($card->unitGuest, $property),
                    $data->ticks,
                    $data->signerName,
                    $signedAt,
                ),
            ])->save();

            $card->addMediaFromString($signature)->usingFileName('signature.png')->toMediaCollection(RegistrationCard::SIGNATURE);

            activity()
                ->performedOn($card)
                ->causedBy($actor)
                ->event('registration_card.signed')
                ->withProperties(['property_id' => $property->id, 'number' => $card->number, 'terms_id' => $resolved->terms->id, 'locale' => $data->locale->value])
                ->log('Registration card signed');

            return true;
        });
    }

    /**
     * True when the ticked positions are exactly the positions of the checklist items.
     *
     * @param  list<int>  $ticks
     */
    private function ticksCover(string $body, array $ticks): bool
    {
        $required = array_keys($this->termsRenderer->items($body));
        $ticked = array_values(array_unique($ticks));
        sort($ticked);

        return $required === $ticked;
    }

    private function identityDocument(User $actor, Guest $guest, SignRegistrationCardData $data): ?GuestIdentityDocument
    {
        if ($data->identity !== null) {
            return ($this->addIdentityDocument)($actor, $guest, $data->identity);
        }

        if ($data->identityDocumentId !== null) {
            return GuestIdentityDocument::query()->whereBelongsTo($guest)->find($data->identityDocumentId);
        }

        return null;
    }

    /**
     * The image bytes of a PNG data URL, or null when it is not one or is too big.
     */
    private function signaturePng(string $dataUrl): ?string
    {
        if (! str_starts_with($dataUrl, self::PNG_PREFIX)) {
            return null;
        }

        $bytes = base64_decode(substr($dataUrl, strlen(self::PNG_PREFIX)), true);

        if ($bytes === false || strlen($bytes) > self::MAX_SIGNATURE_BYTES || ! str_starts_with($bytes, self::PNG_MAGIC)) {
            return null;
        }

        return $bytes;
    }
}
