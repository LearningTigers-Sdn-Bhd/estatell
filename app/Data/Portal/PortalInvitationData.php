<?php

namespace App\Data\Portal;

use App\Models\CompanyUser;
use Spatie\LaravelData\Data;

/**
 * One Pending link on the portal property list, waiting for Accept or Decline.
 */
class PortalInvitationData extends Data
{
    public function __construct(
        public int $linkId,
        public string $propertyName,
        public string $companyName,
    ) {}

    public static function fromLink(CompanyUser $link): self
    {
        return new self(
            linkId: $link->id,
            propertyName: $link->property->name,
            companyName: $link->company->name,
        );
    }
}
