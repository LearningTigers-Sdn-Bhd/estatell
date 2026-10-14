<?php

namespace App\Actions\Guests;

use App\Data\Guests\IdentityDocumentInputData;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class AddIdentityDocument
{
    /**
     * Add an ID document with its optional scans. Returns null when the guest was merged into another guest
     * or anonymized, because those profiles are closed.
     *
     * @param  IdentityDocumentInputData  $data  The validated document fields, with the number normalized.
     */
    public function __invoke(User $actor, Guest $guest, IdentityDocumentInputData $data, ?UploadedFile $frontScan = null, ?UploadedFile $backScan = null): ?GuestIdentityDocument
    {
        return DB::transaction(function () use ($actor, $guest, $data, $frontScan, $backScan): ?GuestIdentityDocument {
            $guest = Guest::query()->lockForUpdate()->findOrFail($guest->id);

            if ($guest->merged_into_id !== null || $guest->anonymized_at !== null) {
                return null;
            }

            $document = new GuestIdentityDocument([
                'type' => $data->type,
                'number' => $data->number,
                'issuing_country_code' => $data->issuingCountryCode,
                'expires_on' => $data->expiresOn,
            ]);
            $document->guest_id = $guest->id;
            $document->save();

            $this->storeScan($document, $frontScan, GuestIdentityDocument::FRONT_SCAN);
            $this->storeScan($document, $backScan, GuestIdentityDocument::BACK_SCAN);

            activity()
                ->performedOn($guest)
                ->causedBy($actor)
                ->event('guest.document_added')
                ->withProperties(['property_id' => $guest->property_id, 'document_id' => $document->id, 'type' => $document->type->value])
                ->log('Guest ID document added');

            return $document;
        });
    }

    private function storeScan(GuestIdentityDocument $document, ?UploadedFile $file, string $collection): void
    {
        if ($file !== null) {
            $document->addMedia($file)->toMediaCollection($collection);
        }
    }
}
