<?php

namespace App\Actions\GuestRequests;

use App\Enums\GuestRequestRefusal;
use App\Enums\GuestRequestStatus;
use App\Models\GuestRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class StartGuestRequest
{
    /**
     * Open to InProgress. A request already InProgress answers done without writing, so a repeat click is not an error.
     */
    public function __invoke(User $actor, GuestRequest $request): GuestRequestResult
    {
        return DB::transaction(function () use ($actor, $request): GuestRequestResult {
            $locked = GuestRequest::query()->where('property_id', $request->property_id)->lockForUpdate()->findOrFail($request->id);

            if ($locked->status === GuestRequestStatus::InProgress) {
                return GuestRequestResult::done($locked);
            }

            if (! $locked->status->canTransitionTo(GuestRequestStatus::InProgress)) {
                return GuestRequestResult::refused(GuestRequestRefusal::InvalidTransition);
            }

            $locked->forceFill(['status' => GuestRequestStatus::InProgress])->save();

            activity()->performedOn($locked)->causedBy($actor)->event('guest_request.started')
                ->withProperties(['property_id' => $locked->property_id])->log('Guest request started');

            return GuestRequestResult::done($locked);
        });
    }
}
