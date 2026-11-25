<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\IssueRoomQrSession;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Pos\Concerns\RespondsToPos;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Front desk issue a new stay PIN for a room QR. The PIN is shown once in the toast and is not stored in plain text.
 */
class PosRoomQrController extends Controller
{
    use RespondsToPos;

    public function regenerate(Property $property, int $unit, IssueRoomQrSession $issueRoomQrSession): RedirectResponse
    {
        $pin = $issueRoomQrSession($property->id, $unit);

        if ($pin === null) {
            $this->toast('unit_not_postable', '');

            return back();
        }

        Inertia::flash('roomQrPin', ['unitId' => $unit, 'pin' => $pin]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Room QR PIN: :pin. Give it to the guest now. It is not shown again.', ['pin' => $pin])]);

        return back();
    }
}
