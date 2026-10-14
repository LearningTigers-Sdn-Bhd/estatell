<?php

namespace App\Data\Registration;

use App\Data\Guests\IdentityDocumentInputData;
use Spatie\LaravelData\Data;

/**
 * The staff draft of a registration card: the ID document, the vehicle plate and the remarks. `identity` is a new
 * document to add to the guest's profile; `identityDocumentId` picks one the guest already has.
 */
class RegistrationCardDraftData extends Data
{
    public function __construct(
        public ?IdentityDocumentInputData $identity,
        public ?int $identityDocumentId,
        public ?string $vehiclePlate,
        public ?string $remarks,
    ) {}
}
