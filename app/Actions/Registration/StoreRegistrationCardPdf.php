<?php

namespace App\Actions\Registration;

use App\Enums\RegistrationCardStatus;
use App\Models\RegistrationCard;
use App\Services\Registration\RegistrationCardDocumentBuilder;
use App\Services\Registration\RegistrationCardPdf;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class StoreRegistrationCardPdf
{
    public function __construct(
        private RegistrationCardDocumentBuilder $builder,
        private RegistrationCardPdf $pdf,
    ) {}

    /**
     * Render the signed card and keep the PDF as the card's `pdf` media. A card that already has one keeps it,
     * because a signed card never changes.
     *
     * Returns null, without writing, when the card is not signed.
     */
    public function __invoke(RegistrationCard $card): ?Media
    {
        if ($card->status !== RegistrationCardStatus::Signed) {
            return null;
        }

        return $card->getFirstMedia(RegistrationCard::PDF)
            ?? $card->addMediaFromString($this->pdf->render($this->builder->forSigned($card)))
                ->usingFileName(str($card->number)->slug().'.pdf')
                ->toMediaCollection(RegistrationCard::PDF);
    }
}
