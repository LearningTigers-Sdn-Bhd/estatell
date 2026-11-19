<?php

namespace App\Actions\Account;

use App\Models\EmailOtp;
use App\Models\User;
use App\Notifications\NewDeviceCodeNotification;
use App\Services\Account\DeviceDescriber;
use App\Services\Account\NewDeviceCodes;
use App\Services\Account\VisitorLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class SendNewDeviceCode
{
    public function __construct(private NewDeviceCodes $codes, private DeviceDescriber $devices, private VisitorLocation $location) {}

    /**
     * Email the user a new 6-digit code for signing in on this browser. It replaces any pending code. Returns false,
     * sending nothing, while the last code is younger than a minute: the user still has that one.
     */
    public function __invoke(User $user, Request $request): bool
    {
        if ($this->codes->secondsUntilResend($user) > 0) {
            return false;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        EmailOtp::query()->upsert(
            [[
                'user_id' => $user->id,
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(NewDeviceCodes::VALID_MINUTES),
                'created_at' => now(),
            ]],
            ['user_id'],
            ['code_hash', 'attempts', 'expires_at', 'created_at'],
        );

        $user->notify(new NewDeviceCodeNotification(
            code: $code,
            device: $this->devices->label($request->userAgent()),
            ipAddress: $request->ip(),
            place: $this->location->label($request),
        ));

        return true;
    }
}
