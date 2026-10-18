<?php

namespace App\Data\Portal;

use App\Data\Property\CurrencyData;
use App\Models\CompanyUser;
use Spatie\LaravelData\Data;

/**
 * One Active link on the portal property list. `state` is `open` for a Live property; otherwise `not_open_yet` or
 * `suspended`, and the row has no Open button.
 */
class PortalPropertyData extends Data
{
    public function __construct(
        public int $linkId,
        public string $propertyName,
        public string $propertyCode,
        public string $companyName,
        public string $state,
        public string $stateLabel,
        public string $openBalance,
        public ?CurrencyData $currency,
        public int $arrivals,
    ) {}

    public static function fromLink(CompanyUser $link, string $state, string $stateLabel, string $openBalance, int $arrivals): self
    {
        return new self(
            linkId: $link->id,
            propertyName: $link->property->name,
            propertyCode: $link->property->code,
            companyName: $link->company->name,
            state: $state,
            stateLabel: $stateLabel,
            openBalance: $openBalance,
            currency: CurrencyData::forProperty($link->property),
            arrivals: $arrivals,
        );
    }
}
