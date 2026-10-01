<?php

namespace App\Actions\Guests;

use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RemoveIdentityDocument
{
    /**
     * Delete the document, its blind index and its scan files.
     */
    public function __invoke(User $actor, GuestIdentityDocument $document): void
    {
        DB::transaction(function () use ($actor, $document): void {
            $guest = Guest::query()->findOrFail($document->guest_id);
            $type = $document->type->value;
            $documentId = $document->id;

            $document->delete();

            activity()
                ->performedOn($guest)
                ->causedBy($actor)
                ->event('guest.document_removed')
                ->withProperties(['property_id' => $guest->property_id, 'document_id' => $documentId, 'type' => $type])
                ->log('Guest ID document removed');
        });
    }
}
