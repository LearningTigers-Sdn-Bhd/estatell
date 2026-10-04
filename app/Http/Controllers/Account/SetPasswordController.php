<?php

namespace App\Http\Controllers\Account;

use App\Actions\Account\SetOwnPassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\SetPasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class SetPasswordController extends Controller
{
    /**
     * The page a user sees after signing in with a temp password.
     */
    public function edit(Request $request): Response|RedirectResponse
    {
        if (! $request->user()->must_change_password) {
            return to_route('home.redirect');
        }

        return Inertia::render('auth/set-password', [
            'email' => $request->user()->email,
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function update(SetPasswordRequest $request, SetOwnPassword $setOwnPassword): RedirectResponse
    {
        if (! $request->user()->must_change_password) {
            return to_route('home.redirect');
        }

        $setOwnPassword($request->user(), $request->string('password')->value());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Your password is set.')]);

        return to_route('home.redirect');
    }
}
