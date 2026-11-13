<?php

namespace App\Actions\GuestRequests;

use App\Enums\GuestRequestRefusal;
use App\Enums\GuestRequestStatus;
use App\Models\GuestRequest;
use App\Models\User;
use App\Services\GuestRequests\GuestRequestDay;
use Illuminate\Support\Facades\DB;

class ResolveGuestRequest
{
    public function __construct(private GuestRequestDay $day) {}

    /**
     * Resolve an Open or InProgress request, stamping the time, the business date and who did it, with an optional note
     * of what was done. A request already Resolved answers done without writing. It does not change the room status.
     */
    public function __invoke(User $actor, GuestRequest $request, ?string $note): GuestRequestResult
    {
        return DB::transaction(function () use ($actor, $request, $note): GuestRequestResult {
            $locked = GuestRequest::query()->where('property_id', $request->property_id)->lockForUpdate()->findOrFail($request->id);

            if ($locked->status === GuestRequestStatus::Resolved) {
                return GuestRequestResult::done($locked);
            }

            if (! $locked->status->canTransitionTo(GuestRequestStatus::Resolved)) {
                return GuestRequestResult::refused(GuestRequestRefusal::InvalidTransition);
            }

            $locked->forceFill([
                'status' => GuestRequestStatus::Resolved,
                'resolved_at' => now(),
                'resolved_business_date' => $this->day->for($locked->property()->firstOrFail())->toDateString(),
                'resolved_by' => $actor->id,
                'resolution_note' => filled($note) ? trim((string) $note) : null,
            ])->save();

            activity()->performedOn($locked)->causedBy($actor)->event('guest_request.resolved')
                ->withProperties(['property_id' => $locked->property_id])->log('Guest request resolved');

            return GuestRequestResult::done($locked);
        });
    }
}
