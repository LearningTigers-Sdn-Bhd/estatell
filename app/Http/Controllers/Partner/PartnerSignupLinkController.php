<?php

namespace App\Http\Controllers\Partner;

use App\Actions\Platform\RegeneratePartnerSignupCode;
use App\Actions\Platform\UpdatePartnerSignupMessage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Partner\UpdatePartnerSignupMessageRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PartnerSignupLinkController extends Controller
{
    /**
     * Issue a new signup link. The old link stops working at once.
     */
    public function regenerate(Request $request, RegeneratePartnerSignupCode $regeneratePartnerSignupCode): RedirectResponse
    {
        $user = $request->user();

        Inertia::flash('toast', $regeneratePartnerSignupCode($user, $user->partner)
            ? ['type' => 'success', 'message' => __('Signup link regenerated. The old link no longer works.')]
            : ['type' => 'error', 'message' => __('The signup link could not be regenerated.')]);

        return to_route('partner.properties.index');
    }

    /**
     * Save the text the partner's "Copy message" button copies.
     */
    public function updateMessage(UpdatePartnerSignupMessageRequest $request, UpdatePartnerSignupMessage $updatePartnerSignupMessage): RedirectResponse
    {
        $user = $request->user();

        Inertia::flash('toast', $updatePartnerSignupMessage($user, $user->partner, $request->string('template')->toString())
            ? ['type' => 'success', 'message' => __('Message saved.')]
            : ['type' => 'error', 'message' => __('The message could not be saved.')]);

        return to_route('partner.properties.index');
    }
}
