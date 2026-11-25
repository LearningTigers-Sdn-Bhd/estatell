<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses PIN routes from a browser that is not a registered, unrevoked terminal device of this property. Runs after
 * `ResolvePosActor`, which finds the device from its cookie.
 */
class RequirePosTerminalDevice
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->attributes->get('pos_device') === null, 403, 'terminal_device_not_registered');

        return $next($request);
    }
}
