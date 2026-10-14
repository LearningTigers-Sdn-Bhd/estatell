<?php

namespace App\Http\Controllers\Partner;

use App\Enums\PartnerStatus;
use App\Http\Controllers\Controller;
use App\Models\Partner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class JoinController extends Controller
{
    public const string SESSION_KEY = 'join_partner_id';

    public const string NOTICE_KEY = 'join_notice';

    /**
     * A partner's signup link. An Active partner is remembered in the session and /register opens "Joining via"
     * that partner. Any other code opens /register with a notice, and forgets an earlier link.
     */
    public function __invoke(Request $request, string $signupCode): RedirectResponse
    {
        $partner = Partner::query()
            ->where('signup_code', strtoupper($signupCode))
            ->where('status', PartnerStatus::Active)
            ->first();

        if ($partner === null) {
            $request->session()->forget(self::SESSION_KEY);

            return to_route('register')->with(self::NOTICE_KEY, __('This link is no longer active'));
        }

        $request->session()->put(self::SESSION_KEY, $partner->id);

        return to_route('register');
    }
}
