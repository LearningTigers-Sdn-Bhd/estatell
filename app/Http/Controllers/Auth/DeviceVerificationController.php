<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Account\ConfirmNewDeviceSignIn;
use App\Actions\Account\SendNewDeviceCode;
use App\Actions\Fortify\RedirectIfTwoFactorOrNewDevice;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyDeviceRequest;
use App\Models\User;
use App\Services\Account\NewDeviceCodes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Contracts\LoginResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The page that asks for the emailed code after a password sign-in on a new device. See
 * docs/01-platform/09-new-device-check.md.
 */
class DeviceVerificationController extends Controller
{
    public function show(Request $request, NewDeviceCodes $codes): Response|RedirectResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return to_route('login');
        }

        return Inertia::render('auth/verify-device', [
            'email' => $this->maskedEmail($user->email),
            'resendInSeconds' => $codes->secondsUntilResend($user),
        ]);
    }

    public function store(VerifyDeviceRequest $request, ConfirmNewDeviceSignIn $confirmNewDeviceSignIn): HttpResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return to_route('login');
        }

        $remember = (bool) $request->session()->get(RedirectIfTwoFactorOrNewDevice::SESSION_REMEMBER_KEY, false);

        if (! $confirmNewDeviceSignIn($user, $request->string('code')->toString(), $remember, $request)) {
            throw ValidationException::withMessages([
                'code' => __('This code is wrong or has expired. Check the latest email or send a new code.'),
            ]);
        }

        $request->session()->forget([RedirectIfTwoFactorOrNewDevice::SESSION_USER_KEY, RedirectIfTwoFactorOrNewDevice::SESSION_REMEMBER_KEY]);

        return app(LoginResponse::class)->toResponse($request);
    }

    public function resend(Request $request, SendNewDeviceCode $sendNewDeviceCode): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return to_route('login');
        }

        if (! $sendNewDeviceCode($user, $request)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Wait a minute before asking for another code.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('A new code is on its way.')]);

        return back();
    }

    /**
     * The user who entered the right password and now waits for the code. Null when the session holds none.
     */
    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get(RedirectIfTwoFactorOrNewDevice::SESSION_USER_KEY);

        return is_int($id) ? User::query()->find($id) : null;
    }

    /**
     * Shows the first letter of the email's name and the whole domain, for example `a***@hotel.com`.
     */
    private function maskedEmail(string $email): string
    {
        [$name, $domain] = explode('@', $email, 2) + [1 => ''];

        return Str::substr($name, 0, 1).'***@'.$domain;
    }
}
