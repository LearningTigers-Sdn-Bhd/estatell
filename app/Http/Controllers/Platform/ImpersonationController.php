<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Platform\StopImpersonation;
use App\Http\Controllers\Controller;
use App\Services\Platform\Impersonation;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ImpersonationController extends Controller
{
    /**
     * End the session and go back to the admin or partner page it started from.
     */
    public function stop(Impersonation $impersonation, StopImpersonation $stopImpersonation): RedirectResponse
    {
        $minutes = $impersonation->elapsedMinutes();
        $returnUrl = $stopImpersonation();

        if ($returnUrl === null) {
            return to_route('home.redirect');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Support session ended (:minutes min).', ['minutes' => $minutes])]);

        return redirect($returnUrl);
    }
}
