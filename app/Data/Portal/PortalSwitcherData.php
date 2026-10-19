<?php

namespace App\Data\Portal;

use App\Models\CompanyUser;
use App\Models\Property;
use Spatie\LaravelData\Data;

/**
 * One property in the portal sidebar's property switcher.
 */
class PortalSwitcherData extends Data
{
    public function __construct(
        public string $propertyName,
        public string $propertyCode,
        public string $companyName,
        public ?string $logoUrl,
    ) {}

    public static function fromLink(CompanyUser $link): self
    {
        return new self(
            propertyName: $link->property->name,
            propertyCode: $link->property->code,
            companyName: $link->company->name,
            logoUrl: self::logoUrl($link->property),
        );
    }

    /**
     * The property logo through the portal route, or null when the property has none. The version in the URL changes
     * with the file.
     */
    public static function logoUrl(Property $property): ?string
    {
        $logo = $property->getFirstMedia(Property::LOGO);

        return $logo === null ? null : route('portal.logo', ['property' => $property->code, 'v' => $logo->id], absolute: false);
    }
}
