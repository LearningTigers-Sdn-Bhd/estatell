<?php

namespace App\Http\Controllers\Partner;

use App\Actions\Platform\AcceptPartnerInvitation;
use App\Data\Platform\PartnerInvitationPreviewData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Partner\AcceptPartnerInvitationRequest;
use App\Models\PartnerInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AcceptPartnerInvitationController extends Controller
{
    /**
     * The accept form, or a notice when the link is unknown, closed or expired.
     */
    public function show(string $token): Response
    {
        $invitation = $this->openInvitation($token);

        return Inertia::render('partner-invitations/accept', [
            'token' => $token,
            'invitation' => $invitation === null ? null : PartnerInvitationPreviewData::fromModel($invitation),
        ]);
    }

    public function store(AcceptPartnerInvitationRequest $request, string $token, AcceptPartnerInvitation $acceptPartnerInvitation): RedirectResponse
    {
        $invitation = $this->openInvitation($token);
        $user = $invitation === null ? false : $acceptPartnerInvitation($invitation, $request->toAcceptPartnerInvitationData());

        if ($user === false) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This invitation link is no longer valid.')]);

            return to_route('partner-invitations.show', $token);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return to_route('home.redirect');
    }

    private function openInvitation(string $token): ?PartnerInvitation
    {
        return PartnerInvitation::query()
            ->open()
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->with('partner')
            ->first();
    }
}
