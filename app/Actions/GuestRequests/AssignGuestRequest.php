<?php

namespace App\Actions\GuestRequests;

use App\Enums\GuestRequestRefusal;
use App\Models\GuestRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AssignGuestRequest
{
    /**
     * Give the request to a member of the same property, or take it back with null. The caller checks that the person
     * may work on requests; a person of another property is refused here whatever their permissions.
     */
    public function __invoke(User $actor, GuestRequest $request, ?User $assignee): GuestRequestResult
    {
        if ($assignee !== null && $assignee->property_id !== $request->property_id) {
            return GuestRequestResult::refused(GuestRequestRefusal::AssigneeNotAllowed);
        }

        return DB::transaction(function () use ($actor, $request, $assignee): GuestRequestResult {
            $locked = GuestRequest::query()->where('property_id', $request->property_id)->lockForUpdate()->findOrFail($request->id);

            if ($locked->assigned_to === $assignee?->id) {
                return GuestRequestResult::done($locked);
            }

            $locked->forceFill(['assigned_to' => $assignee?->id])->save();

            activity()->performedOn($locked)->causedBy($actor)->event('guest_request.assigned')
                ->withProperties(['property_id' => $locked->property_id, 'assigned' => $assignee !== null])->log('Guest request assigned');

            return GuestRequestResult::done($locked);
        });
    }
}
