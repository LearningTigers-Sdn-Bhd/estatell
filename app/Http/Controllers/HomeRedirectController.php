<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class HomeRedirectController extends Controller
{
    /**
     * Send a signed-in user to their home: the admin dashboard or their property's dashboard.
     * Fortify's `home` points here, so every auth flow lands in the right place.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->is_admin) {
            return to_route('admin.dashboard');
        }

        return to_route('dashboard', ['property' => $user->property->code]);
    }
}
