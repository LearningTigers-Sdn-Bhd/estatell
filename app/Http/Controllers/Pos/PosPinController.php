<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\SwitchPosActor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\SwitchPosActorRequest;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class PosPinController extends Controller
{
    public function switch(SwitchPosActorRequest $request, Property $property, SwitchPosActor $switchPosActor): RedirectResponse
    {
        $target = User::query()->where('property_id', $property->id)->find((int) $request->validated('user_id'));

        $result = $target === null
            ? null
            : $switchPosActor($request->session(), $property, $request->attributes->get('pos_device'), $target, (string) $request->validated('pin'));

        Inertia::flash('toast', $result?->isVerified()
            ? ['type' => 'success', 'message' => __('Signed in as :name.', ['name' => $target->name])]
            : ['type' => 'error', 'message' => $result === null ? __('That PIN is not right.') : $result->outcome->label()]);

        return back();
    }
}
