<?php

namespace App\Actions\Platform;

use App\Actions\Account\RecordSignIn;
use App\Services\Platform\Impersonation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Session;

class StopImpersonation
{
    public function __construct(private Impersonation $impersonation, private RecordImpersonationEnd $recordEnd) {}

    /**
     * Log the end of the session, sign the support user back in and return the path they started from.
     * Returns null when there is no session.
     */
    public function __invoke(): ?string
    {
        $supportUser = $this->impersonation->supportUser();

        if ($supportUser === null) {
            return null;
        }

        $returnUrl = $this->impersonation->returnUrl();

        ($this->recordEnd)();

        Session::forget(Impersonation::SESSION_KEY);
        Context::scope(fn () => Auth::login($supportUser), hidden: [RecordSignIn::SUPPORT_SWITCH_CONTEXT_KEY => true]);

        return $returnUrl;
    }
}
