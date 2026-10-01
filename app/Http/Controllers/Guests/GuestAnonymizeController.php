<?php

namespace App\Http\Controllers\Guests;

use App\Actions\Guests\AnonymizeGuest;
use App\Http\Controllers\Controller;
use App\Models\Guest;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GuestAnonymizeController extends Controller
{
    public function store(Request $request, Property $property, Guest $guest, AnonymizeGuest $anonymizeGuest): RedirectResponse
    {
        $name = $guest->full_name;

        Inertia::flash('toast', $anonymizeGuest($request->user(), $guest)
            ? ['type' => 'success', 'message' => __(':name anonymized.', ['name' => $name])]
            : ['type' => 'error', 'message' => __(':name cannot be anonymized.', ['name' => $name])]);

        return to_route('guests.show', ['property' => $property, 'guest' => $guest]);
    }
}
