<?php

namespace App\Actions\Platform;

use App\Services\Platform\Impersonation;

class EndExpiredImpersonation
{
    public function __construct(private Impersonation $impersonation, private StopImpersonation $stopImpersonation) {}

    /**
     * End the session the same way Stop does when it has run past the 30-minute limit, and return the path to send
     * the support user to. Returns null, and changes nothing, when there is no session or it has not expired.
     */
    public function __invoke(): ?string
    {
        if (! $this->impersonation->hasExpired()) {
            return null;
        }

        return ($this->stopImpersonation)();
    }
}
