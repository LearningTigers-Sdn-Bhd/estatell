<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While a browser session is in POS Terminal Mode it may only use the `/pos` routes (and sign out). Finance, settings,
 * front desk, reports, admin and account pages answer 403 until a fresh password leaves Terminal Mode, so a shared till
 * never exposes the back office of whoever started it.
 */
class BlockBackOfficeInTerminalMode
{
    /**
     * Route names allowed besides `pos.*`.
     *
     * @var list<string>
     */
    private const ALLOWED = ['logout', 'property.logo'];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession() && $request->session()->get('pos_terminal_locked') === true && ! $request->routeIs('pos.*', ...self::ALLOWED)) {
            abort(403, 'terminal_mode_locked');
        }

        return $next($request);
    }
}
