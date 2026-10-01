<?php

namespace App\Actions\Guests;

use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\User;

class RecordIdentityDocumentAccess
{
    /**
     * Log that staff saw a document's full number or opened one of its scans.
     * Pass the scan collection (`id_front` or `id_back`) when a scan was opened.
     */
    public function __invoke(User $actor, GuestIdentityDocument $document, ?string $scan = null): void
    {
        $guest = Guest::query()->findOrFail($document->guest_id);

        activity()
            ->performedOn($guest)
            ->causedBy($actor)
            ->event($scan === null ? 'guest.document_number_viewed' : 'guest.document_scan_viewed')
            ->withProperties(array_filter([
                'property_id' => $guest->property_id,
                'document_id' => $document->id,
                'scan' => $scan,
            ]))
            ->log($scan === null ? 'Guest ID number viewed' : 'Guest ID scan viewed');
    }
}
