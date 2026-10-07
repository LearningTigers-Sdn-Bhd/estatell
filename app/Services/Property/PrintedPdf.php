<?php

namespace App\Services\Property;

use App\Enums\PdfFont;
use App\Models\Property;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Support\Facades\File;

/**
 * Renders a view on the shared PDF layout with the property's print settings. The view gets the layout as `$print`.
 * When any text needs Noto Sans SC the whole document uses it, whatever the setting, so Chinese text never prints as
 * empty boxes. It writes nothing but dompdf's font cache in `storage/fonts`.
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

        $print = $this->printProfile->for($property);
        $html = view($view, [...$data, 'print' => $print])->render();

        if ($print->font !== PdfFont::Chinese && self::needsCjk($html)) {
            $html = view($view, [...$data, 'print' => $print->withFont(PdfFont::Chinese)])->render();
        }

        return Pdf::loadHTML($html)
            ->setPaper('a4', $orientation)
            ->setOption('isFontSubsettingEnabled', true);
    }

    /**
     * True when the text has Han, Hiragana, Katakana or Hangul characters, which DejaVu Sans does not cover.
     */
    public static function needsCjk(string $text): bool
    {
        return preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $text) === 1;
    }
}
