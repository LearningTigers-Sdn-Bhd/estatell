<?php

namespace App\Http\Controllers\Portal;

use App\Actions\CityLedger\AcceptCompanyLink;
use App\Actions\CityLedger\DeclineCompanyLink;
use App\Http\Controllers\Controller;
use App\Models\CompanyUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PortalLinkController extends Controller
{
    /**
     * Accept the user's own invitation. Someone else's link is a 404.
     */
    public function accept(Request $request, CompanyUser $companyUser, AcceptCompanyLink $acceptCompanyLink): RedirectResponse
    {
        abort_unless($companyUser->user_id === $request->user()->id, 404);

        $refusal = $acceptCompanyLink($request->user(), $companyUser);

        Inertia::flash('toast', $refusal === null
            ? ['type' => 'success', 'message' => __(':company linked.', ['company' => $companyUser->company->name])]
            : ['type' => 'error', 'message' => $refusal->message()]);

        return to_route('portal.index');
    }

    /**
     * Decline the user's own invitation. Someone else's link is a 404.
     */
    public function decline(Request $request, CompanyUser $companyUser, DeclineCompanyLink $declineCompanyLink): RedirectResponse
    {
        abort_unless($companyUser->user_id === $request->user()->id, 404);

        Inertia::flash('toast', $declineCompanyLink($request->user(), $companyUser)
            ? ['type' => 'success', 'message' => __('Invitation declined.')]
            : ['type' => 'error', 'message' => __('This invitation is no longer open.')]);

        return to_route('portal.index');
    }
}
