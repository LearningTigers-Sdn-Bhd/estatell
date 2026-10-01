<?php

namespace App\Http\Middleware;

use App\Models\Guest;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * A guest that was merged into another guest is hidden. Opening its page goes to the guest it was merged into;
 * any other request to it is a 404. Merging always points to the final guest, so one redirect is enough.
 */
class EnsureGuestIsNotMerged
{
    public function handle(Request $request, Closure $next): Response
    {
        $guest = $request->route('guest');

        if (! $guest instanceof Guest || $guest->merged_into_id === null) {
            return $next($request);
        }

        $survivor = Guest::query()->where('property_id', $guest->property_id)->find($guest->merged_into_id);

        abort_if($survivor === null || ! $request->isMethod('GET'), 404);

        Inertia::flash('toast', ['type' => 'info', 'message' => __(':name was merged into :survivor.', ['name' => $guest->full_name, 'survivor' => $survivor->full_name])]);

        return redirect()->route($request->route()->getName(), [...$request->route()->parameters(), 'guest' => $survivor]);
    }
}
