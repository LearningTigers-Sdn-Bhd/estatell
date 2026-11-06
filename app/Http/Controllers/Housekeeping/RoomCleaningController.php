<?php

namespace App\Http\Controllers\Housekeeping;

use App\Actions\Housekeeping\DeleteRoomCleaning;
use App\Actions\Housekeeping\FinishRoomCleaning;
use App\Actions\Housekeeping\SaveRoomCleaning;
use App\Actions\Housekeeping\StartRoomCleaning;
use App\Enums\HousekeepingRefusal;
use App\Enums\PermissionEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Housekeeping\SaveRoomCleaningRequest;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCleaning;
use App\Services\Housekeeping\RoomCleanings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The housekeeping IN and OUT times of a room. Every route goes back to the page it came from: the board or House
 * status. See docs/19-housekeeping/03-cleaning-times.md.
 */
class RoomCleaningController extends Controller
{
    public function start(Request $request, Property $property, Room $room, StartRoomCleaning $start, RoomCleanings $cleanings): RedirectResponse
    {
        abort_if($room->archived_at !== null, 404);

        $user = $request->user();
        $housekeeper = $cleanings->housekeeperFor($user, $room, $user->can(PermissionEnum::HousekeepingEditTimes->value));

        return $this->back($start($user, $room, $housekeeper), $room);
    }

    public function finish(Request $request, Property $property, Room $room, FinishRoomCleaning $finish): RedirectResponse
    {
        return $this->back($finish($request->user(), $room), $room);
    }

    public function store(SaveRoomCleaningRequest $request, Property $property, Room $room, SaveRoomCleaning $save): RedirectResponse
    {
        abort_if($room->archived_at !== null, 404);

        return $this->back($save($request->user(), $room, null, $request->housekeeper(), $request->string('in_time')->toString(), $request->input('out_time')), $room);
    }

    public function update(SaveRoomCleaningRequest $request, Property $property, Room $room, RoomCleaning $cleaning, SaveRoomCleaning $save): RedirectResponse
    {
        return $this->back($save($request->user(), $room, $cleaning, $request->housekeeper(), $request->string('in_time')->toString(), $request->input('out_time')), $room);
    }

    public function destroy(Request $request, Property $property, Room $room, RoomCleaning $cleaning, DeleteRoomCleaning $delete): RedirectResponse
    {
        $delete($request->user(), $cleaning);

        return back();
    }

    private function back(?HousekeepingRefusal $refusal, Room $room): RedirectResponse
    {
        if ($refusal !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $refusal->message($room->number)]);
        }

        return back();
    }
}
