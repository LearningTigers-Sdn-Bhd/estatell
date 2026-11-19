<?php

namespace App\Actions\Account;

use App\Models\EmailOtp;
use App\Models\User;
use App\Notifications\NewDeviceCodeNotification;
use App\Services\Account\DeviceDescriber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class SendNewDeviceCode
{
    /**
     * Minutes a code stays valid.
     */
    public const int VALID_MINUTES = 15;

    /**
     * Seconds before another code can be sent.
     */
    public const int RESEND_SECONDS = 60;

    public function __construct(private DeviceDescriber $devices) {}

    /**
     * Email the user a new 6-digit code for signing in on this browser. It replaces any pending code. Returns false,
     * sending nothing, while the last code is younger than a minute: the user still has that one.
     */
    public function __invoke(User $user, Request $request): bool
    {
        $pending = EmailOtp::query()->whereBelongsTo($user)->first();

        if ($pending !== null && $pending->created_at->addSeconds(self::RESEND_SECONDS)->isFuture()) {
            return false;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        EmailOtp::query()->updateOrCreate(['user_id' => $user->id], [
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(self::VALID_MINUTES),
            'created_at' => now(),
        ]);

        $user->notify(new NewDeviceCodeNotification(
            code: $code,
            device: $this->devices->label($request->userAgent()),
            ipAddress: $request->ip(),
            place: collect([$request->header('cf-ipcity'), $request->header('CF-IPCountry')])->filter(fn (mixed $part): bool => is_string($part) && $part !== '' && $part !== 'XX')->implode(', ') ?: null,
        ));

        return true;
    }
}
