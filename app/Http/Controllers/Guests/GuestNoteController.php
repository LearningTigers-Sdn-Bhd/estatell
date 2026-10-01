<?php

namespace App\Http\Controllers\Guests;

use App\Actions\Guests\AddGuestNote;
use App\Http\Controllers\Controller;
use App\Http\Requests\Guests\GuestNoteRequest;
use App\Models\Guest;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class GuestNoteController extends Controller
{
    public function store(GuestNoteRequest $request, Property $property, Guest $guest, AddGuestNote $addGuestNote): RedirectResponse
    {
        $addGuestNote($request->user(), $guest, $request->validated('body'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Note added.')]);

        return to_route('guests.notes.index', ['property' => $property, 'guest' => $guest]);
    }
}
