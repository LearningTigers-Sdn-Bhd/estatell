<?php

namespace App\Http\Middleware;

use App\Features\Pos;
use App\Models\Property;
use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 404 to POS staff pages while the property's POS feature is off. The Posport bridge API and guest QR routes
 * get a 403 `pos_disabled` instead, so a device or printed QR code can tell "off" from "wrong URL".
 */
class EnsurePosIsActive
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $property = $request->route('property');

        if ($property instanceof Property && Feature::for($property)->active(Pos::class)) {
            return $next($request);
        }

        if ($request->is('api/posport/*', 'q/*')) {
            return response()->json(['message' => 'pos_disabled'], 403);
        }

        abort(404);
    }
}
