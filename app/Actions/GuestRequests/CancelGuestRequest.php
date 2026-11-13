<?php

namespace App\Actions\GuestRequests;

use App\Enums\GuestRequestRefusal;
use App\Enums\GuestRequestStatus;
use App\Models\GuestRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CancelGuestRequest
{
    /**
     * Cancel an Open or InProgress request. The note is required, for example "Duplicate". A cancel keeps who and when in
     * the activity log only, because a Cancelled request is left out of the KPIs. A request already Cancelled answers
     * done without writing.
     */
    public function __invoke(User $actor, GuestRequest $request, string $note): GuestRequestResult
    {
        if (trim($note) === '') {
            return GuestRequestResult::refused(GuestRequestRefusal::NoteRequired);
        }

        return DB::transaction(function () use ($actor, $request, $note): GuestRequestResult {
            $locked = GuestRequest::query()->where('property_id', $request->property_id)->lockForUpdate()->findOrFail($request->id);

            if ($locked->status === GuestRequestStatus::Cancelled) {
                return GuestRequestResult::done($locked);
            }

            if (! $locked->status->canTransitionTo(GuestRequestStatus::Cancelled)) {
                return GuestRequestResult::refused(GuestRequestRefusal::InvalidTransition);
            }

            $locked->forceFill(['status' => GuestRequestStatus::Cancelled, 'resolution_note' => trim($note)])->save();

            activity()->performedOn($locked)->causedBy($actor)->event('guest_request.cancelled')
                ->withProperties(['property_id' => $locked->property_id])->log('Guest request cancelled');

            return GuestRequestResult::done($locked);
        });
    }
}
