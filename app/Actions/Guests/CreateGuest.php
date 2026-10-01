<?php

namespace App\Actions\Guests;

use App\Models\Guest;
use App\Models\Property;
use App\Models\User;
use App\Services\Guests\GuestName;
use Illuminate\Support\Facades\DB;

class CreateGuest
{
    public function __construct(private SyncGuestTags $syncGuestTags, private GuestName $guestName) {}

    /**
     * Add a guest to the property.
     *
     * @param  array<string, mixed>  $attributes  Validated profile fields.
     * @param  list<string>  $tagNames
     */
    public function __invoke(User $actor, Property $property, array $attributes, array $tagNames = []): Guest
    {
        return DB::transaction(function () use ($actor, $property, $attributes, $tagNames): Guest {
            $guest = new Guest($attributes);
            $guest->property_id = $property->id;
            $guest->name_key = $this->guestName->key($guest->full_name);
            $guest->save();

            ($this->syncGuestTags)($guest, $tagNames);

            activity()
                ->performedOn($guest)
                ->causedBy($actor)
                ->event('guest.created')
                ->withProperties(['property_id' => $property->id])
                ->log('Guest created');

            return $guest;
        });
    }
}
