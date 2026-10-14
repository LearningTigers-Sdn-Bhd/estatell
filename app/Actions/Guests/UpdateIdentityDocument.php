<?php

namespace App\Actions\Guests;

use App\Data\Guests\IdentityDocumentInputData;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class UpdateIdentityDocument
{
    /**
     * Save the document's fields and scans. A new file replaces the old scan and the old file is deleted.
     * Returns false when the guest was merged into another guest or anonymized.
     *
     * @param  IdentityDocumentInputData  $data  The validated document fields, with the number normalized.
     * @param  list<string>  $removeScans  Scan collections to empty, for example `id_back`.
     */
    public function __invoke(User $actor, GuestIdentityDocument $document, IdentityDocumentInputData $data, ?UploadedFile $frontScan = null, ?UploadedFile $backScan = null, array $removeScans = []): bool
    {
        return DB::transaction(function () use ($actor, $document, $data, $frontScan, $backScan, $removeScans): bool {
            $guest = Guest::query()->lockForUpdate()->findOrFail($document->guest_id);

            if ($guest->merged_into_id !== null || $guest->anonymized_at !== null) {
                return false;
            }

            $document = GuestIdentityDocument::query()->findOrFail($document->id);
            $document->fill([
                'type' => $data->type,
                'number' => $data->number,
                'issuing_country_code' => $data->issuingCountryCode,
                'expires_on' => $data->expiresOn,
            ]);
            $changed = array_keys($document->getDirty());
            $document->save();

            foreach ([GuestIdentityDocument::FRONT_SCAN => $frontScan, GuestIdentityDocument::BACK_SCAN => $backScan] as $collection => $file) {
                if ($file !== null) {
                    $document->addMedia($file)->toMediaCollection($collection);
                    $changed[] = $collection;
                } elseif (in_array($collection, $removeScans, true) && $document->hasMedia($collection)) {
                    $document->clearMediaCollection($collection);
                    $changed[] = $collection;
                }
            }

            activity()
                ->performedOn($guest)
                ->causedBy($actor)
                ->event('guest.document_updated')
                ->withProperties(['property_id' => $guest->property_id, 'document_id' => $document->id, 'changed' => $changed])
                ->log('Guest ID document updated');

            return true;
        });
    }
}
