<?php

namespace App\Actions\GuestRequests;

use App\Enums\GuestRequestRefusal;
use App\Enums\GuestRequestStatus;
use App\Models\GuestRequest;
use App\Models\User;
use App\Services\GuestRequests\GuestRequestDay;
use Illuminate\Support\Facades\DB;

class ReopenGuestRequest
{
    public function __construct(private GuestRequestDay $day) {}

    /**
     * Resolved or Cancelled back to Open. A Resolved request reopens only while its business date is still the open one,
     * so the KPI of a closed date never changes: else `resolved_on_closed_date`. A Cancelled request reopens any time.
     * Reopening clears the resolution. A request already Open answers done without writing.
     */
    public function __invoke(User $actor, GuestRequest $request): GuestRequestResult
    {
        return DB::transaction(function () use ($actor, $request): GuestRequestResult {
            $locked = GuestRequest::query()->where('property_id', $request->property_id)->lockForUpdate()->findOrFail($request->id);

            if ($locked->status === GuestRequestStatus::Open) {
                return GuestRequestResult::done($locked);
            }

            if (! $locked->status->canTransitionTo(GuestRequestStatus::Open) || $locked->status === GuestRequestStatus::InProgress) {
                return GuestRequestResult::refused(GuestRequestRefusal::InvalidTransition);
            }

            if ($locked->status === GuestRequestStatus::Resolved && $locked->resolved_business_date?->toDateString() !== $this->day->for($locked->property()->firstOrFail())->toDateString()) {
                return GuestRequestResult::refused(GuestRequestRefusal::ResolvedOnClosedDate);
            }

            $locked->forceFill([
                'status' => GuestRequestStatus::Open,
                'resolved_at' => null,
                'resolved_business_date' => null,
                'resolved_by' => null,
                'resolution_note' => null,
            ])->save();

            activity()->performedOn($locked)->causedBy($actor)->event('guest_request.reopened')
                ->withProperties(['property_id' => $locked->property_id])->log('Guest request reopened');

            return GuestRequestResult::done($locked);
        });
    }
}
