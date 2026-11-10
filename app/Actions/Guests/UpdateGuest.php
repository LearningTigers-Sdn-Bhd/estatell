<?php

namespace App\Actions\Guests;

use App\Data\Guests\IdentityDocumentInputData;
use App\Models\Guest;
use App\Models\User;
use App\Services\Guests\GuestName;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class UpdateGuest
{
    public function __construct(private SyncGuestTags $syncGuestTags, private GuestName $guestName, private AddIdentityDocument $addIdentityDocument, private UpdateIdentityDocument $updateIdentityDocument) {}

    /**
     * Save the guest's profile fields and tags. Returns false when the guest was merged into another guest
     * or anonymized, because those profiles are closed.
     *
     * @param  array<string, mixed>  $attributes  Validated profile fields.
     * @param  list<string>  $tagNames
     */
    public function __invoke(?User $actor, Guest $guest, array $attributes, array $tagNames = [], ?IdentityDocumentInputData $identity = null, ?UploadedFile $identityPhoto = null, ?int $identityDocumentId = null): bool
    {
        return DB::transaction(function () use ($actor, $guest, $attributes, $tagNames, $identity, $identityPhoto, $identityDocumentId): bool {
            $guest = Guest::query()->lockForUpdate()->findOrFail($guest->id);

            if ($guest->merged_into_id !== null || $guest->anonymized_at !== null) {
                return false;
            }

            $guest->fill($attributes);
            $changed = array_keys($guest->getDirty());
            $guest->name_key = $this->guestName->key($guest->full_name);
            $guest->save();

            ($this->syncGuestTags)($guest, $tagNames);

            if ($identity !== null) {
                $document = $identityDocumentId !== null
                    ? $guest->documents()->findOrFail($identityDocumentId)
                    : $guest->documents()->where('type', $identity->type)->oldest('id')->first();
                if ($document === null) {
                    ($this->addIdentityDocument)($actor, $guest, $identity, $identityPhoto);
                } else {
                    $identity->expiresOn = $document->expires_on?->toDateString();
                    ($this->updateIdentityDocument)($actor, $document, $identity, $identityPhoto);
                }
            }

            activity()
                ->performedOn($guest)
                ->causedBy($actor)
                ->event('guest.updated')
                ->withProperties(['property_id' => $guest->property_id, 'changed' => $changed])
                ->log('Guest updated');

            return true;
        });
    }
}
