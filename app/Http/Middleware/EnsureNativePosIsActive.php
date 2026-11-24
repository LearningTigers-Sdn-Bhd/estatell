<?php

namespace App\Http\Middleware;

use App\Features\NativePos;
use App\Models\Property;
use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 404 to Native POS staff pages while the property's Native POS feature is off. The Posport bridge API and the
 * guest QR routes get a 403 `native_pos_disabled` instead, so a device or a printed QR code can tell "off" from "wrong URL".
 */
class EnsureNativePosIsActive
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $property = $request->route('property');

        if ($property instanceof Property && Feature::for($property)->active(NativePos::class)) {
            return $next($request);
        }

        if ($request->is('api/posport/*', 'q/*')) {
            return response()->json(['message' => 'native_pos_disabled'], 403);
        }

        abort(404);
    }
}
