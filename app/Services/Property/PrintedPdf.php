<?php

namespace App\Services\Property;

use App\Models\Property;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Support\Facades\File;

/**
 * Renders a view on the shared PDF layout with the property's print settings. The view gets the layout as `$print`.
 * Chinese text prints in every font style: dompdf draws a character the style's fonts lack with the next font in the
 * font-family list (Noto Sans SC, then Noto Sans TC). It writes nothing but dompdf's font cache in `storage/fonts`.
 */
class PrintedPdf
{
    public function __construct(private PrintProfile $printProfile) {}

    /**
     * @param  view-string  $view
     * @param  array<string, mixed>  $data
     * @param  'portrait'|'landscape'  $orientation
     */
    public function make(Property $property, string $view, array $data, string $orientation = 'portrait'): DomPdf
    {
        File::ensureDirectoryExists(storage_path('fonts'));

        return Pdf::loadView($view, [...$data, 'print' => $this->printProfile->for($property)])
            ->setPaper('a4', $orientation)
            ->setOption('isFontSubsettingEnabled', true);
    }
}
