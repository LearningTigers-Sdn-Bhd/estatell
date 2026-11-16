<?php

namespace App\Actions\Account;

use App\Enums\SignInResult;
use App\Models\SignInEvent;
use App\Models\User;
use App\Services\Account\DeviceDescriber;
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

    public function __construct(private DeviceDescriber $devices) {}

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
            'country_code' => $this->countryCode($request),
            'city' => $this->place($request, 'cf-ipcity'),
            'region' => $this->place($request, 'cf-region'),
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
     * A city or region from Cloudflare's visitor location headers, cut to the column length. Empty when missing.
     */
    private function place(Request $request, string $header): ?string
    {
        $value = trim((string) $request->header($header));

        return $value === '' ? null : mb_substr($value, 0, 80);
    }

    /**
     * The network number the `X-Client-ASN` transform rule adds. Anything other than a positive number is ignored.
     */
    private function asn(Request $request): ?int
    {
        $asn = (string) $request->header('X-Client-ASN');

        return preg_match('/^[1-9]\d{0,9}$/', $asn) === 1 && (int) $asn <= 4294967295 ? (int) $asn : null;
    }

    /**
     * The country Cloudflare found for the IP. Cloudflare sends `XX` when it does not know and `T1` for Tor, so only
     * two letters other than `XX` count.
     */
    private function countryCode(Request $request): ?string
    {
        $code = strtoupper((string) $request->header('CF-IPCountry'));

        return preg_match('/^[A-Z]{2}$/', $code) === 1 && $code !== 'XX' ? $code : null;
    }
}
