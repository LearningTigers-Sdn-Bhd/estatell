<?php

namespace App\Actions\Account;

use App\Models\EmailOtp;
use App\Models\User;
use App\Services\Account\NewDeviceCodes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class ConfirmNewDeviceSignIn
{
    public function __construct(private TrustDevice $trustDevice) {}

    /**
     * Check the emailed code, then sign the user in and trust this browser. Returns false, signing no one in, when
     * the user was deactivated meanwhile, there is no pending code, it expired, it was tried too often, or the code is
     * wrong. Each try is counted in one conditional update before the code is checked, so parallel requests can never
     * get more than MAX_ATTEMPTS tries.
     */
    public function __invoke(User $user, string $code, bool $remember, Request $request): bool
    {
        $pending = $user->deactivated_at === null ? EmailOtp::query()->whereBelongsTo($user)->first() : null;

        if ($pending === null) {
            return false;
        }

        $claimed = EmailOtp::query()
            ->whereKey($pending->id)
            ->where('attempts', '<', NewDeviceCodes::MAX_ATTEMPTS)
            ->where('expires_at', '>', now())
            ->increment('attempts');

        if ($claimed === 0 || ! Hash::check($code, $pending->code_hash)) {
            return false;
        }

        $pending->delete();

        Auth::login($user, $remember);
        ($this->trustDevice)($user, $request);

        return true;
    }
}
