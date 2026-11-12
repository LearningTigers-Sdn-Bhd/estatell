<?php

namespace App\Actions\Guests;

use App\Actions\Webhooks\TriggerWebhook;
use App\Data\Guests\IdentityDocumentInputData;
use App\Enums\WebhookEvent;
use App\Models\Guest;
use App\Models\Property;
use App\Models\User;
use App\Services\Guests\GuestName;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class CreateGuest
{
    public function __construct(private SyncGuestTags $syncGuestTags, private GuestName $guestName, private AddIdentityDocument $addIdentityDocument) {}

    /**
     * Add a guest to the property.
     *
     * @param  array<string, mixed>  $attributes  Validated profile fields.
     * @param  list<string>  $tagNames
     */
    public function __invoke(?User $actor, Property $property, array $attributes, array $tagNames = [], ?IdentityDocumentInputData $identity = null, ?UploadedFile $identityPhoto = null): Guest
    {
        return DB::transaction(function () use ($actor, $property, $attributes, $tagNames, $identity, $identityPhoto): Guest {
            $guest = new Guest($attributes);
            $guest->property_id = $property->id;
            $guest->name_key = $this->guestName->key($guest->full_name);
            $guest->save();

            ($this->syncGuestTags)($guest, $tagNames);

            if ($identity !== null) {
                ($this->addIdentityDocument)($actor, $guest, $identity, $identityPhoto);
            }

            activity()
                ->performedOn($guest)
                ->causedBy($actor)
                ->event('guest.created')
                ->withProperties(['property_id' => $property->id])
                ->log('Guest created');

            app(TriggerWebhook::class)(WebhookEvent::GuestCreated, $guest->property_id, Guest::class, $guest->id);

            return $guest;
        });
    }
}
