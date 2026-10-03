<?php

namespace App\Actions\Guests;

use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\User;
use App\Services\Folio\FolioBalance;
use App\Services\Guests\GuestName;
use App\Services\Reservations\GuestInHouse;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

class AnonymizeGuest
{
    /**
     * Profile fields that are cleared.
     */
    private const array PERSONAL_FIELDS = [
        'email', 'phone', 'nationality_code', 'date_of_birth', 'gender', 'preferred_locale',
        'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country_code',
        'blacklisted_at', 'blacklist_reason', 'marketing_consent_at',
    ];

    public function __construct(
        private GuestName $guestName,
        private GuestInHouse $guestInHouse,
        private FolioBalance $folioBalance,
    ) {}

    /**
     * Remove the guest's personal data and keep the record, so financial history stays intact.
     * The name becomes `Anonymized guest #id`. Notes, tag links and ID documents (numbers, blind indexes and
     * scan files) are deleted, and the blacklist reason is removed from earlier activity log entries.
     *
     * Returns false when the guest is already anonymized, was merged into another guest, is in house
     * (an occupant of a checked-in unit), or pays a folio with a balance other than 0. This cannot be undone.
     *
     * Modules that keep a guest's records add their own checks here.
     */
    public function __invoke(User $actor, Guest $guest): bool
    {
        return DB::transaction(function () use ($actor, $guest): bool {
            $guest = Guest::query()->lockForUpdate()->findOrFail($guest->id);

            if ($guest->anonymized_at !== null || $guest->merged_into_id !== null) {
                return false;
            }

            if ($this->guestInHouse->isInHouse($guest)) {
                return false;
            }

            if ($this->folioBalance->guestHasOpenBalance($guest)) {
                return false;
            }

            $documentsRemoved = $guest->documents()->get()->each(fn (GuestIdentityDocument $document) => $document->delete())->count();
            $notesRemoved = $guest->notes()->delete();
            $guest->tags()->detach();

            $guest->forceFill(array_fill_keys(self::PERSONAL_FIELDS, null))->forceFill([
                'full_name' => __('Anonymized guest #:id', ['id' => $guest->id]),
                'is_vip' => false,
                'anonymized_at' => now(),
            ]);
            $guest->name_key = $this->guestName->key($guest->full_name);
            $guest->save();

            $this->removeReasonsFromActivity($guest);

            activity()
                ->performedOn($guest)
                ->causedBy($actor)
                ->event('guest.anonymized')
                ->withProperties([
                    'property_id' => $guest->property_id,
                    'notes_removed' => $notesRemoved,
                    'documents_removed' => $documentsRemoved,
                ])
                ->log('Guest anonymized');

            return true;
        });
    }

    /**
     * A blacklist reason can name people, so it does not stay in the log.
     */
    private function removeReasonsFromActivity(Guest $guest): void
    {
        Activity::query()
            ->where('subject_type', $guest->getMorphClass())
            ->where('subject_id', $guest->id)
            ->get()
            ->each(function (Activity $activity): void {
                if ($activity->properties->has('reason')) {
                    $activity->properties = $activity->properties->except('reason');
                    $activity->save();
                }
            });
    }
}
