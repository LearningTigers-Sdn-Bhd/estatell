<?php

namespace App\Data\Property;

use App\Enums\PdfFont;
use App\Enums\PdfFontSize;
use App\Models\PrintSetting;
use Spatie\LaravelData\Data;

class PrintSettingsData extends Data
{
    /**
     * @param  list<int>  $taxIds  The taxes whose numbers the header shows.
     */
    public function __construct(
        public PdfFont $font,
        public PdfFontSize $fontSize,
        public ?string $footerText,
        public bool $showLogo,
        public bool $showAddress,
        public bool $showContact,
        public bool $showRegistrationNumber,
        public bool $showTin,
        public array $taxIds,
    ) {}

    public static function fromModel(PrintSetting $settings): self
    {
        return new self(
            font: $settings->font,
            fontSize: $settings->font_size,
            footerText: $settings->footer_text,
            showLogo: $settings->show_logo,
            showAddress: $settings->show_address,
            showContact: $settings->show_contact,
            showRegistrationNumber: $settings->show_registration_number,
            showTin: $settings->show_tin,
            taxIds: array_values(array_map(intval(...), $settings->taxes->modelKeys())),
        );
    }
}
