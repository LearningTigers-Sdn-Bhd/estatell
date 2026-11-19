<?php

namespace App\Actions\Account;

use App\Enums\SignInResult;
use App\Models\SignInEvent;
use App\Models\User;
use App\Services\Account\DeviceDescriber;
use App\Services\Account\VisitorLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

/**
 * Writes one row of the sign-in log. The typed email, the password and the full user agent are never stored. See
 * docs/01-platform/07-sign-in-log.md.
 */
class RecordSignIn
{
    /**
     * Hidden Context key set while support access switches the signed-in user. That switch is not a sign-in.
     */
    public const string SUPPORT_SWITCH_CONTEXT_KEY = 'sign_in_log.support_switch';

    public function __construct(private DeviceDescriber $devices, private VisitorLocation $location) {}

    /**
     * Returns false without writing during a support access switch.
     */
    public function __invoke(SignInResult $result, ?User $user, Request $request): bool
    {
        if (Context::hasHidden(self::SUPPORT_SWITCH_CONTEXT_KEY)) {
            return false;
        }

        $event = SignInEvent::query()->create([
            'user_id' => $user?->id,
            'result' => $result,
            'ip_address' => $request->ip() ?? 'unknown',
            'country_code' => $this->location->countryCode($request),
            'city' => $this->location->place($request, 'cf-ipcity'),
            'region' => $this->location->place($request, 'cf-region'),
            'asn' => $this->asn($request),
            ...$this->devices->describe($request->userAgent()),
            'occurred_at' => now(),
            'last_active_at' => $result === SignInResult::Succeeded ? now() : null,
        ]);

        if ($result === SignInResult::Succeeded && $request->hasSession()) {
            $request->session()->put([SignInEvent::SESSION_KEY => $event->id, TouchSignInSession::TOUCHED_AT_KEY => now()->getTimestamp()]);
        }

        return true;
    }

    /**
     * The network number the `X-Client-ASN` transform rule adds. Anything other than a positive number is ignored.
     */
    private function asn(Request $request): ?int
    {
        $asn = (string) $request->header('X-Client-ASN');

        return preg_match('/^[1-9]\d{0,9}$/', $asn) === 1 && (int) $asn <= 4294967295 ? (int) $asn : null;
    }
}
