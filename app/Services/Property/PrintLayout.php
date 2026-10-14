<?php

namespace App\Services\Property;

use App\Enums\PdfFont;
use App\Enums\PdfFontSize;

/**
 * What the shared PDF layout and the letterhead partial draw for one property. Built by PrintProfile.
 */
final readonly class PrintLayout
{
    /**
     * @param  list<string>  $addressLines
     * @param  list<string>  $contactLines
     * @param  list<string>  $registrationLines  The business registration, TIN, printed licences and tax numbers that are switched on.
     */
    public function __construct(
        public PdfFont $font,
        public PdfFontSize $fontSize,
        public string $propertyName,
        public ?string $legalName,
        public ?string $logoDataUri,
        public array $addressLines,
        public array $contactLines,
        public array $registrationLines,
        public ?string $footerText,
    ) {}

}
