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
     * @param  list<string>  $registrationLines  The business registration, TIN and tax numbers that are switched on.
     */
    public function __construct(
        public PdfFont $font,
        public PdfFontSize $fontSize,
        public string $propertyName,
        public ?string $logoDataUri,
        public array $addressLines,
        public array $contactLines,
        public array $registrationLines,
        public ?string $footerText,
    ) {}

    /**
     * The same layout with another font, used when the text needs Noto Sans SC.
     */
    public function withFont(PdfFont $font): self
    {
        return new self($font, $this->fontSize, $this->propertyName, $this->logoDataUri, $this->addressLines, $this->contactLines, $this->registrationLines, $this->footerText);
    }
}
