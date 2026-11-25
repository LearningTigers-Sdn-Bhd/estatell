<?php

namespace App\Http\Middleware;

use App\Models\PosStaffPin;
use App\Models\Property;
use App\Models\User;
use App\Services\Pos\PosTerminal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Finds who is acting at a POS route and which terminal device the browser is. In Terminal Mode the actor is the staff
 * member who signed in by PIN (or nobody yet), never the browser user, so a manager's permissions cannot leak to a
 * cashier. Outside Terminal Mode (a browser that is neither locked nor a registered device) the actor is the signed-in user. Sets the `pos_actor` and `pos_device` request attributes.
 */
class ResolvePosActor
{
    public function __construct(private PosTerminal $terminal) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $property = $request->route('property');

        abort_unless($property instanceof Property, 404);

        $request->attributes->set('pos_device', $this->terminal->device($request, $property));
        $request->attributes->set('pos_actor', $this->terminal->isLocked($request, $property) ? $this->pinActor($request, $property) : $request->user());

        return $next($request);
    }

    private function pinActor(Request $request, Property $property): ?User
    {
        $actorId = $request->session()->get('pos_active_actor_id');

        if (! is_int($actorId)) {
            return null;
        }

        $actor = User::query()->where('property_id', $property->id)->whereNull('deactivated_at')->find($actorId);
        $hasPin = $actor !== null && PosStaffPin::query()->where('property_id', $property->id)->where('user_id', $actor->id)->where('active', true)->exists();

        if (! $hasPin) {
            $request->session()->forget('pos_active_actor_id');

            return null;
        }

        return $actor;
    }
}
