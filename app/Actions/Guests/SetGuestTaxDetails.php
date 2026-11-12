<?php

namespace App\Actions\Guests;

use App\Data\Guests\IdentityDocumentInputData;
use App\Enums\IdentityDocumentType;
use App\Models\Guest;
use App\Models\User;
use App\Services\Guests\IdentityNumber;
use Illuminate\Support\Facades\DB;

/**
 * Saves the tax number and ID that LHDN accepted on a guest's e-invoice to their profile, so the next stay starts
 * from them. The ID updates the guest's document of that type, or adds one; a document's expiry and scans stay.
 * A merged or anonymized guest is left alone. Returns whether anything changed.
 */
class SetGuestTaxDetails
{
    public function __construct(
        private AddIdentityDocument $addIdentityDocument,
        private UpdateIdentityDocument $updateIdentityDocument,
        private IdentityNumber $identityNumber,
    ) {}

    public function __invoke(Guest $guest, ?string $tin, ?IdentityDocumentType $idType, ?string $idNumber, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($guest, $tin, $idType, $idNumber, $actor): bool {
            $guest = Guest::query()->lockForUpdate()->findOrFail($guest->id);

            if ($guest->merged_into_id !== null || $guest->anonymized_at !== null) {
                return false;
            }

            $changed = false;
            $tin = strtoupper(trim((string) $tin));

            if ($tin !== '' && ! str_starts_with($tin, 'EI') && $guest->tin !== $tin) {
                $guest->tin = $tin;
                $guest->save();
                $changed = true;

                activity()
                    ->performedOn($guest)
                    ->causedBy($actor)
                    ->event('guest.updated')
                    ->withProperties(['property_id' => $guest->property_id, 'changed' => ['tin'], 'source' => 'e_invoice'])
                    ->log('Guest updated');
            }

            $number = $idNumber === null ? '' : $this->identityNumber->normalize($idNumber);
            if ($idType === null || $number === '' || $number === 'NA') {
                return $changed;
            }

            $document = $guest->documents()->where('type', $idType)->oldest('id')->first();
            if ($document !== null && $this->identityNumber->normalize((string) $document->number) === $number) {
                return $changed;
            }

            $input = new IdentityDocumentInputData($idType, $number, $document?->issuing_country_code, $document?->expires_on?->toDateString());
            $document === null
                ? ($this->addIdentityDocument)($actor, $guest, $input)
                : ($this->updateIdentityDocument)($actor, $document, $input);

            return true;
        });
    }
}
