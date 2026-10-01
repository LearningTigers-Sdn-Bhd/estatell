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
        Inertia::flash('toast', $addGuestNote($request->user(), $guest, $request->validated('body')) !== null
            ? ['type' => 'success', 'message' => __('Note added.')]
            : ['type' => 'error', 'message' => __(':name cannot be edited.', ['name' => $guest->full_name])]);

        return to_route('guests.notes.index', ['property' => $property, 'guest' => $guest]);
    }
}
