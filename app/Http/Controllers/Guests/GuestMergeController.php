<?php

namespace App\Http\Controllers\Guests;

use App\Actions\Guests\MergeGuests;
use App\Http\Controllers\Controller;
use App\Http\Requests\Guests\MergeGuestsRequest;
use App\Models\Guest;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class GuestMergeController extends Controller
{
    /**
     * Merge the chosen duplicate into the guest whose page it was started from.
     */
    public function store(MergeGuestsRequest $request, Property $property, Guest $guest, MergeGuests $mergeGuests): RedirectResponse
    {
        $duplicate = $property->guests()->findOrFail($request->integer('duplicate_id'));
        $duplicateName = $duplicate->full_name;

        Inertia::flash('toast', $mergeGuests($request->user(), $guest, $duplicate)
            ? ['type' => 'success', 'message' => __(':duplicate merged into :name.', ['duplicate' => $duplicateName, 'name' => $guest->full_name])]
            : ['type' => 'error', 'message' => __(':duplicate cannot be merged into :name.', ['duplicate' => $duplicateName, 'name' => $guest->full_name])]);

        return to_route('guests.show', ['property' => $property, 'guest' => $guest]);
    }
}
