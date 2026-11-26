<?php

namespace App\Http\Middleware;

use App\Services\Pos\PosTerminal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While a browser session is in POS Terminal Mode, or is a registered terminal device not yet unlocked with a password, it may only use the `/pos` routes (and sign out). Finance, settings,
 * front desk, reports, admin and account pages answer 403 until a fresh password leaves Terminal Mode, so a shared till
 * never exposes the back office of whoever started it.
 */
class BlockBackOfficeInTerminalMode
{
    public function __construct(private PosTerminal $terminal) {}

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
        // A guest has no back office to protect, and blocking them would leave a signed-out till unable to sign in.
        if ($request->user() !== null && ! $request->routeIs('pos.*', ...self::ALLOWED) && $this->terminal->isLocked($request)) {
            abort(403, 'terminal_mode_locked');
        }

        return $next($request);
    }
}
