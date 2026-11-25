<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Support\SessionKey;
use Symfony\Component\HttpFoundation\Response;

/**
 * Leaves the session's Inertia flash (the toast of the last action) alone for a page that polls in the background.
 * Inertia hands the flash to whichever page renders next in the session, so the customer-facing display, which shares the
 * cashier's session and reloads every few seconds, would show the cashier's toast to the guest and take it from the register.
 */
class KeepInertiaFlash
{
    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->hasSession() ? $request->session() : null;
        $flash = $session?->pull(SessionKey::FLASH_DATA);

        $response = $next($request);

        if ($session !== null && $flash !== null) {
            $session->put(SessionKey::FLASH_DATA, $flash);
        }

        return $response;
    }
}
