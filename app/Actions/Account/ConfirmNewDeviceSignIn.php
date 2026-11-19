<?php

namespace App\Actions\Account;

use App\Models\EmailOtp;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class ConfirmNewDeviceSignIn
{
    /**
     * Wrong codes a pending code takes before it stops working and a new one must be sent.
     */
    public const int MAX_ATTEMPTS = 5;

    public function __construct(private TrustDevice $trustDevice) {}

    /**
     * Check the emailed code, then sign the user in and trust this browser. Returns false, signing no one in, when
     * there is no pending code, it expired, it was guessed wrong too often, the code is wrong (counted), or the user
     * was deactivated meanwhile.
     */
    public function __invoke(User $user, string $code, bool $remember, Request $request): bool
    {
        $pending = EmailOtp::query()->whereBelongsTo($user)->first();

        if ($pending === null || $pending->expires_at->isPast() || $pending->attempts >= self::MAX_ATTEMPTS || $user->deactivated_at !== null) {
            return false;
        }

        if (! Hash::check($code, $pending->code_hash)) {
            $pending->increment('attempts');

            return false;
        }

        $pending->delete();

        Auth::login($user, $remember);
        ($this->trustDevice)($user, $request);

        return true;
    }
}
