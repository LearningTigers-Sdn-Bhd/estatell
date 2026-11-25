<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\InvitePosStaffToSetPin;
use App\Actions\Pos\RevokePosStaffPin;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Pos\Concerns\RespondsToPos;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PosStaffPinController extends Controller
{
    use RespondsToPos;

    public function invite(Request $request, Property $property, int $user, InvitePosStaffToSetPin $invitePosStaffToSetPin): RedirectResponse
    {
        $target = User::query()->where('property_id', $property->id)->findOrFail($user);

        $this->toast($invitePosStaffToSetPin($request->user(), $property, $target)->refusal, __('Invitation sent to :name.', ['name' => $target->name]));

        return back();
    }

    public function revoke(Request $request, Property $property, int $user, RevokePosStaffPin $revokePosStaffPin): RedirectResponse
    {
        $target = User::query()->where('property_id', $property->id)->findOrFail($user);

        Inertia::flash('toast', $revokePosStaffPin($request->user(), $property, $target)
            ? ['type' => 'success', 'message' => __('PIN cleared. :name must set a new one.', ['name' => $target->name])]
            : ['type' => 'error', 'message' => __(':name has no POS PIN.', ['name' => $target->name])]);

        return back();
    }
}
