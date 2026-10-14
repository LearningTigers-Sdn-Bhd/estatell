<?php

namespace App\Http\Controllers\Staff;

use App\Actions\Staff\AcceptStaffInvitation;
use App\Data\Staff\InvitationPreviewData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\AcceptInvitationRequest;
use App\Models\StaffInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AcceptInvitationController extends Controller
{
    /**
     * The accept form, or a notice when the link is unknown, closed or expired.
     */
    public function show(string $token): Response
    {
        $invitation = $this->openInvitation($token);

        return Inertia::render('invitations/accept', [
            'token' => $token,
            'invitation' => $invitation === null ? null : InvitationPreviewData::fromModel($invitation),
        ]);
    }

    public function store(AcceptInvitationRequest $request, string $token, AcceptStaffInvitation $acceptStaffInvitation): RedirectResponse
    {
        $invitation = $this->openInvitation($token);
        $user = $invitation === null ? false : $acceptStaffInvitation($invitation, $request->toAcceptStaffInvitationData());

        if ($user === false) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This invitation link is no longer valid.')]);

            return to_route('invitations.show', $token);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return to_route('home.redirect');
    }

    private function openInvitation(string $token): ?StaffInvitation
    {
        return StaffInvitation::query()
            ->open()
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->with('property', 'role')
            ->first();
    }
}
