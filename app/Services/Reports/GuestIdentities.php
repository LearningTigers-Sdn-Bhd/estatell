<?php

namespace App\Services\Reports;

use App\Enums\IdentityDocumentType;
use App\Enums\PermissionEnum;
use App\Models\GuestIdentityDocument;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The ID shown for a guest in the Non-national guests and Police reports: the guest's latest document, a Passport
 * before any other type, then the newest by `created_at` and `id`, written as "Passport · A1234567". The documents are
 * picked from their plain columns with one query; only the picked documents are loaded (and decrypted) with a second
 * one, so a selected-row export decrypts only the selected rows.
 *
 * A user without `guests.view-documents` gets the masked number (`•••• 4567`), a user with it gets the full number.
 * See docs/11-guests/02-identity-documents.md.
 */
class GuestIdentities
{
    public const string SEPARATOR = ' · ';

    /**
     * Whether the signed-in user sees full ID numbers.
     */
    public function canSeeFullNumbers(): bool
    {
        return Auth::user()?->can(PermissionEnum::GuestsViewDocuments->value) ?? false;
    }

    /**
     * @param  list<int>  $guestIds  Guests of one property.
     * @return array<int, string> The text by guest id; a guest with no document has no entry.
     */
    public function forGuests(array $guestIds): array
    {
        return array_map(self::text(...), $this->partsForGuests($guestIds));
    }

    /**
     * The "Passport · A1234567" text of one document, or an empty string for a guest with none.
     *
     * @param  array{type: string, number: string}|null  $parts
     */
    public static function text(?array $parts): string
    {
        return $parts === null ? '' : $parts['type'].self::SEPARATOR.$parts['number'];
    }

    /**
     * The same document as `forGuests()`, with the type label and the number apart. The number is masked by the same rule.
     *
     * @param  list<int>  $guestIds  Guests of one property.
     * @return array<int, array{type: string, number: string}> By guest id; a guest with no document has no entry.
     */
    public function partsForGuests(array $guestIds): array
    {
        if ($guestIds === []) {
            return [];
        }

        $chosen = DB::table('guest_identity_documents')
            ->whereIn('guest_id', array_values(array_unique($guestIds)))
            ->get(['id', 'guest_id', 'type', 'created_at'])
            ->sort(fn (object $a, object $b): int => [(int) ($b->type === IdentityDocumentType::Passport->value), (string) $b->created_at, $b->id] <=> [(int) ($a->type === IdentityDocumentType::Passport->value), (string) $a->created_at, $a->id])
            ->unique('guest_id')
            ->pluck('id');

        $full = $this->canSeeFullNumbers();

        return GuestIdentityDocument::query()
            ->whereIn('id', $chosen)
            ->get()
            ->mapWithKeys(fn (GuestIdentityDocument $document): array => [
                $document->guest_id => [
                    'type' => $document->type->label(),
                    'number' => $full ? $document->formattedNumber() : $document->maskedNumber(),
                ],
            ])
            ->all();
    }
}
