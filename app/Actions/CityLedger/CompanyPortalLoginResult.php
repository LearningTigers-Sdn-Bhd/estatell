<?php

namespace App\Actions\CityLedger;

use App\Enums\PortalLoginOutcome;
use App\Enums\PortalLoginRefusal;
use App\Models\CompanyUser;

/**
 * What `SetCompanyPortalLogin` did. A refusal writes and logs nothing.
 */
readonly class CompanyPortalLoginResult
{
    public function __construct(
        public PortalLoginOutcome $outcome,
        public ?CompanyUser $link = null,
        public ?PortalLoginRefusal $refusal = null,
    ) {}

    public function isRefused(): bool
    {
        return $this->outcome === PortalLoginOutcome::Refused;
    }

    /**
     * True when the call made a login with a temp password that the property can hand out.
     */
    public function createdLogin(): bool
    {
        return $this->outcome === PortalLoginOutcome::Created;
    }
}
