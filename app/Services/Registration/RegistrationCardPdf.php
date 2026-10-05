<?php

namespace App\Services\Registration;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;

/**
 * Renders a card document to PDF bytes in the document's language. It writes nothing but dompdf's font cache in `storage/fonts`.
 */
class RegistrationCardPdf
{
    public function render(RegistrationCardDocument $document): string
    {
        File::ensureDirectoryExists(storage_path('fonts'));

        $previous = app()->getLocale();
        app()->setLocale($document->locale->value);

        try {
            return Pdf::loadView('pdf.registration-card', ['document' => $document])
                ->setPaper('a4', 'portrait')
                ->setOption('isFontSubsettingEnabled', true)
                ->output();
        } finally {
            app()->setLocale($previous);
        }
    }
}
