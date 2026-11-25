<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `pos.can:{permission}`: the POS version of `can:`. It checks the PIN-selected actor from `ResolvePosActor`, never the
 * browser user, because a shared terminal's browser session may belong to a manager.
 */
class EnsurePosActorCan
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $actor = $request->attributes->get('pos_actor');

        abort_unless($actor instanceof User && $actor->checkPermissionTo($permission), 403);

        return $next($request);
    }
}
