<?php

namespace App\Actions\Guests;

use App\Actions\Folio\RepointGuestFolios;
use App\Actions\Reservations\RepointGuestReservations;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\GuestNote;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class MergeGuests
{
    public function __construct(
        private RepointGuestReservations $repointGuestReservations,
        private RepointGuestFolios $repointGuestFolios,
    ) {}

    /**
     * Profile fields that are filled from the merged guest when the survivor has none. They are cleared on the merged guest.
     */
    private const array PERSONAL_FIELDS = [
        'email', 'phone', 'nationality_code', 'date_of_birth', 'gender', 'preferred_locale',
        'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country_code',
    ];

    /**
     * Merge `$merged` into `$survivor`: notes, tags and ID documents move over, empty profile fields are filled,
     * and the merged guest keeps only its name and a pointer to the survivor.
     *
     * Returns false when nothing was merged: the two are the same guest, belong to different properties,
     * or either one was already merged into another guest or anonymized.
     *
     * Reservations re-point the booker and the unit occupants through `RepointGuestReservations`.
     * Folios that the merged guest pays move to the survivor through `RepointGuestFolios`.
     * Modules that point at a guest add their own re-pointing here.
     */
    public function __invoke(User $actor, Guest $survivor, Guest $merged): bool
    {
        if ($survivor->id === $merged->id) {
            return false;
        }

        return DB::transaction(function () use ($actor, $survivor, $merged): bool {
            $locked = Guest::query()->whereIn('id', [$survivor->id, $merged->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $survivor = $locked->get($survivor->id);
            $merged = $locked->get($merged->id);

            if ($survivor === null || $merged === null || $survivor->property_id !== $merged->property_id) {
                return false;
            }

            foreach ([$survivor, $merged] as $guest) {
                if ($guest->merged_into_id !== null || $guest->anonymized_at !== null) {
                    return false;
                }
            }

            $filled = [];

            foreach (self::PERSONAL_FIELDS as $field) {
                if ($survivor->getAttribute($field) === null && $merged->getAttribute($field) !== null) {
                    $survivor->setAttribute($field, $merged->getAttribute($field));
                    $filled[] = $field;
                }
            }

            $vipCarried = $merged->is_vip && ! $survivor->is_vip;
            $blacklistCarried = $merged->blacklisted_at !== null && $survivor->blacklisted_at === null;

            if ($vipCarried) {
                $survivor->is_vip = true;
            }

            if ($blacklistCarried) {
                $survivor->blacklisted_at = $merged->blacklisted_at;
                $survivor->blacklist_reason = $merged->blacklist_reason;
            }

            $survivor->marketing_consent_at ??= $merged->marketing_consent_at;

            $notesMoved = GuestNote::query()->where('guest_id', $merged->id)->update(['guest_id' => $survivor->id]);
            $documentsMoved = GuestIdentityDocument::query()->where('guest_id', $merged->id)->update(['guest_id' => $survivor->id]);
            $tagIds = $merged->tags()->pluck('tags.id')->all();
            $tagsAdded = count($survivor->tags()->syncWithoutDetaching($tagIds)['attached']);
            $merged->tags()->detach();

            $survivor->save();

            $reservations = ($this->repointGuestReservations)($survivor, $merged);
            $folios = ($this->repointGuestFolios)($survivor, $merged);

            $merged->forceFill(array_fill_keys(self::PERSONAL_FIELDS, null))->forceFill([
                'is_vip' => false,
                'blacklisted_at' => null,
                'blacklist_reason' => null,
                'marketing_consent_at' => null,
                'merged_into_id' => $survivor->id,
            ])->save();

            Guest::query()->where('merged_into_id', $merged->id)->update(['merged_into_id' => $survivor->id]);

            activity()
                ->performedOn($survivor)
                ->causedBy($actor)
                ->event('guest.merged')
                ->withProperties([
                    'property_id' => $survivor->property_id,
                    'merged_guest_id' => $merged->id,
                    'notes_moved' => $notesMoved,
                    'documents_moved' => $documentsMoved,
                    'tags_added' => $tagsAdded,
                    'reservations_moved' => $reservations['reservations'],
                    'unit_guests_moved' => $reservations['unit_guests'],
                    'unit_guest_duplicates_removed' => $reservations['duplicates_removed'],
                    'folios_moved' => $folios['folios'],
                    'fields_filled' => $filled,
                    'vip_carried' => $vipCarried,
                    'blacklist_carried' => $blacklistCarried,
                ])
                ->log('Guests merged');

            return true;
        });
    }
}
