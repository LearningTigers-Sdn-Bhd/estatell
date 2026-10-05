<?php

namespace App\Actions\Registration;

use App\Models\TermsDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteTermsDocument
{
    /**
     * Delete a terms document with all its versions.
     *
     * Returns false, without writing, for the default document or one a room type uses.
     */
    public function __invoke(TermsDocument $document, User $actor): bool
    {
        return DB::transaction(function () use ($document, $actor): bool {
            $document = TermsDocument::query()->lockForUpdate()->findOrFail($document->id);

            if ($document->is_default || $document->roomTypes()->exists()) {
                return false;
            }

            $document->delete();

            activity()
                ->performedOn($document)
                ->causedBy($actor)
                ->event('terms_document.deleted')
                ->withProperties(['property_id' => $document->property_id, 'name' => $document->name])
                ->log('Terms document deleted');

            return true;
        });
    }
}
