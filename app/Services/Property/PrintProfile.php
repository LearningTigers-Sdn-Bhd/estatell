<?php

namespace App\Services\Property;

use App\Models\PrintSetting;
use App\Models\Property;
use App\Models\Tax;
use App\Services\Guests\CountryOptions;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Builds the letterhead, font and footer of a property's PDFs from its print settings. The settings store switches;
 * the values come from the property, its logo and its taxes. It reads and writes nothing else.
 * See docs/02-property/05-printing-settings.md.
 */
class PrintProfile
{
    public function __construct(private CountryOptions $countryOptions) {}

    public function for(Property $property): PrintLayout
    {
        $property->loadMissing(['printSetting.taxes', 'media']);
        $settings = $property->printSetting;

        return new PrintLayout(
            font: $settings->font,
            fontSize: $settings->font_size,
            propertyName: $property->name,
            logoDataUri: $settings->show_logo ? $this->logoDataUri($property) : null,
            addressLines: $settings->show_address ? $this->addressLines($property) : [],
            contactLines: $settings->show_contact ? $this->contactLines($property) : [],
            registrationLines: $this->registrationLines($property, $settings),
            footerText: $settings->footer_text,
        );
    }

    /**
     * dompdf reads the logo as a data URI, so a file on R2 prints the same as a local one.
     */
    private function logoDataUri(Property $property): ?string
    {
        $media = $property->getFirstMedia(Property::LOGO);

        if (! $media instanceof Media) {
            return null;
        }

        $stream = $media->stream();
        $contents = stream_get_contents($stream);
        fclose($stream);

        return $contents === false ? null : 'data:'.$media->mime_type.';base64,'.base64_encode($contents);
    }

    /**
     * @return list<string>
     */
    private function addressLines(Property $property): array
    {
        return array_values(array_filter([
            $property->address_line1,
            $property->address_line2,
            trim(($property->postal_code ?? '').' '.($property->city ?? '')),
            $this->countryOptions->name($property->country_code),
        ], filled(...)));
    }

    /**
     * Phone and email on one line.
     *
     * @return list<string>
     */
    private function contactLines(Property $property): array
    {
        $line = implode(' · ', array_filter([$property->contact_phone, $property->contact_email], filled(...)));

        return $line === '' ? [] : [$line];
    }

    /**
     * The registration number, the TIN and the picked tax numbers, each only when switched on and set. A picked tax
     * whose number was removed later is skipped.
     *
     * @return list<string>
     */
    private function registrationLines(Property $property, PrintSetting $settings): array
    {
        $lines = [];

        if ($settings->show_registration_number && filled($property->registration_number)) {
            $lines[] = __('Registration no. :number', ['number' => $property->registration_number]);
        }

        if ($settings->show_tin && filled($property->tin)) {
            $lines[] = __('TIN :number', ['number' => $property->tin]);
        }

        $taxes = $settings->taxes
            ->filter(fn (Tax $tax): bool => filled($tax->registration_number))
            ->sortBy([['enabled', 'desc'], ['name', 'asc'], ['id', 'asc']]);

        foreach ($taxes as $tax) {
            $lines[] = $tax->code.' '.$tax->registration_number;
        }

        return $lines;
    }
}
