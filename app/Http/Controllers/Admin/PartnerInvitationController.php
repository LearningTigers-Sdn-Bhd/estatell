<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Platform\InvitePartner;
use App\Actions\Platform\ResendPartnerInvitation;
use App\Actions\Platform\RevokePartnerInvitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InvitePartnerRequest;
use App\Models\PartnerInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PartnerInvitationController extends Controller
{
    public function store(InvitePartnerRequest $request, InvitePartner $invitePartner): RedirectResponse
    {
        $invitation = $invitePartner(
            $request->user(),
            $request->validated('partner_name'),
            $request->validated('code_prefix'),
            $request->validated('email'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent to :email.', ['email' => $invitation->email])]);

        return to_route('admin.partners.invited');
    }

    public function resend(Request $request, PartnerInvitation $partnerInvitation, ResendPartnerInvitation $resendPartnerInvitation): RedirectResponse
    {
        Inertia::flash('toast', $resendPartnerInvitation($request->user(), $partnerInvitation)
            ? ['type' => 'success', 'message' => __('Invitation resent to :email.', ['email' => $partnerInvitation->email])]
            : ['type' => 'error', 'message' => __('This invitation is no longer open.')]);

        return back(fallback: route('admin.partners.invited'));
    }

    public function destroy(Request $request, PartnerInvitation $partnerInvitation, RevokePartnerInvitation $revokePartnerInvitation): RedirectResponse
    {
        Inertia::flash('toast', $revokePartnerInvitation($request->user(), $partnerInvitation)
            ? ['type' => 'success', 'message' => __('Invitation to :email revoked.', ['email' => $partnerInvitation->email])]
            : ['type' => 'error', 'message' => __('This invitation is no longer open.')]);

        return back(fallback: route('admin.partners.invited'));
    }
}
